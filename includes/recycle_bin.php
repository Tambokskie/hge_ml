<?php

require_once __DIR__ . '/functions.php';

/**
 * Copies one saved graduate into graduate_recycle_bin. Does NOT delete the
 * original and does NOT open its own transaction — callers run this inside
 * the same transaction as their DELETE so a failure leaves both untouched.
 * Returns false when the graduate doesn't exist.
 */
function archive_graduate(PDO $pdo, int $graduateId, ?int $userId, string $reason, string $via = 'manual'): bool
{
    $stmt = $pdo->prepare(
        'INSERT INTO graduate_recycle_bin
            (original_id, upload_id, school_id, graduate_name, course, latin_honor, graduation_date, graduation_year,
             source, original_created_at, deleted_by, deleted_at, deleted_reason, deleted_via)
         SELECT id, upload_id, school_id, graduate_name, course, latin_honor, graduation_date, graduation_year,
                source, created_at, :deleted_by, :deleted_at, :reason, :via
         FROM latin_honor_graduates WHERE id = :id'
    );
    $stmt->execute([
        'deleted_by' => $userId,
        'deleted_at' => date('Y-m-d H:i:s'),
        'reason' => mb_substr($reason, 0, 500),
        'via' => $via,
        'id' => $graduateId,
    ]);

    return $stmt->rowCount() > 0;
}

/**
 * @return array{rows: array, pagination: array}
 */
function get_recycle_bin_items(PDO $pdo, array $filters, int $page, int $perPage = 25): array
{
    $conditions = [];
    $params = [];

    $name = trim((string) ($filters['name'] ?? ''));
    if ($name !== '') {
        $conditions[] = 'b.graduate_name LIKE :name';
        $params['name'] = '%' . addcslashes($name, '\\%_') . '%';
    }

    $via = (string) ($filters['via'] ?? '');
    if (in_array($via, ['manual', 'duplicate_cleanup', 'review_edit'], true)) {
        $conditions[] = 'b.deleted_via = :via';
        $params['via'] = $via;
    }

    $whereSql = empty($conditions) ? '' : ('WHERE ' . implode(' AND ', $conditions));

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM graduate_recycle_bin b {$whereSql}");
    $countStmt->execute($params);
    $pagination = paginate((int) $countStmt->fetchColumn(), $page, $perPage);

    $stmt = $pdo->prepare(
        "SELECT b.id, b.original_id, b.graduate_name, b.course, b.latin_honor, b.graduation_date, b.graduation_year,
                b.deleted_at, b.deleted_reason, b.deleted_via, s.school_name, s.campus, u.name AS deleted_by_name
         FROM graduate_recycle_bin b
         LEFT JOIN schools s ON s.id = b.school_id
         LEFT JOIN users u ON u.id = b.deleted_by
         {$whereSql}
         ORDER BY b.deleted_at DESC, b.id DESC
         LIMIT :limit OFFSET :offset"
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue('limit', $pagination['per_page'], PDO::PARAM_INT);
    $stmt->bindValue('offset', $pagination['offset'], PDO::PARAM_INT);
    $stmt->execute();

    return ['rows' => $stmt->fetchAll(), 'pagination' => $pagination];
}

/**
 * Re-inserts a binned graduate under its ORIGINAL id (so activity-log
 * entries and any external references still line up) and removes the bin
 * row. Fails with a readable error rather than throwing when the parent
 * upload/school no longer exists or the id has since been reused.
 *
 * @return array{ok: bool, error?: string}
 */
function restore_graduate(PDO $pdo, int $binId): array
{
    $stmt = $pdo->prepare('SELECT * FROM graduate_recycle_bin WHERE id = :id');
    $stmt->execute(['id' => $binId]);
    $item = $stmt->fetch();

    if ($item === false) {
        return ['ok' => false, 'error' => 'RECYCLE BIN ITEM NOT FOUND.'];
    }

    $exists = $pdo->prepare('SELECT 1 FROM latin_honor_graduates WHERE id = :id');
    $exists->execute(['id' => $item['original_id']]);
    if ($exists->fetchColumn() !== false) {
        return ['ok' => false, 'error' => 'A RECORD WITH THIS ID ALREADY EXISTS AND THIS ITEM CANNOT BE RESTORED.'];
    }

    $parent = $pdo->prepare('SELECT (SELECT COUNT(*) FROM latin_honor_uploads WHERE id = :upload_id) AS u, (SELECT COUNT(*) FROM schools WHERE id = :school_id) AS s');
    $parent->execute(['upload_id' => $item['upload_id'], 'school_id' => $item['school_id']]);
    $found = $parent->fetch();
    if ((int) $found['u'] === 0 || (int) $found['s'] === 0) {
        return ['ok' => false, 'error' => 'THE ORIGINAL UPLOAD OR SCHOOL NO LONGER EXISTS, SO THIS RECORD CANNOT BE RESTORED.'];
    }

    try {
        $pdo->beginTransaction();

        $pdo->prepare(
            'INSERT INTO latin_honor_graduates
                (id, upload_id, school_id, graduate_name, course, latin_honor, graduation_date, graduation_year, source, created_at)
             VALUES (:id, :upload_id, :school_id, :graduate_name, :course, :latin_honor, :graduation_date, :graduation_year, :source, COALESCE(:created_at, CURRENT_TIMESTAMP))'
        )->execute([
            'id' => $item['original_id'],
            'upload_id' => $item['upload_id'],
            'school_id' => $item['school_id'],
            'graduate_name' => $item['graduate_name'],
            'course' => $item['course'],
            'latin_honor' => $item['latin_honor'],
            'graduation_date' => $item['graduation_date'],
            'graduation_year' => $item['graduation_year'],
            'source' => $item['source'],
            'created_at' => $item['original_created_at'],
        ]);
        $pdo->prepare('UPDATE latin_honor_uploads SET records_extracted = records_extracted + 1 WHERE id = :id')
            ->execute(['id' => $item['upload_id']]);
        $pdo->prepare('DELETE FROM graduate_recycle_bin WHERE id = :id')->execute(['id' => $binId]);

        $pdo->commit();
    } catch (\Throwable $e) {
        try {
            $pdo->rollBack();
        } catch (\Throwable $rollbackError) {
            error_log('restore_graduate rollback failed: ' . $rollbackError->getMessage());
        }
        error_log('restore_graduate failed: ' . $e->getMessage());

        return ['ok' => false, 'error' => 'THE RECORD COULD NOT BE RESTORED. PLEASE TRY AGAIN.'];
    }

    return ['ok' => true];
}

/**
 * Permanently deletes one bin item. Returns the item's name so the caller
 * can put it in the activity log, or null when it doesn't exist.
 */
function purge_recycle_bin_item(PDO $pdo, int $binId): ?string
{
    $stmt = $pdo->prepare('SELECT graduate_name FROM graduate_recycle_bin WHERE id = :id');
    $stmt->execute(['id' => $binId]);
    $name = $stmt->fetchColumn();

    if ($name === false) {
        return null;
    }

    $pdo->prepare('DELETE FROM graduate_recycle_bin WHERE id = :id')->execute(['id' => $binId]);

    return (string) $name;
}
