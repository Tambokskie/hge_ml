<?php

require_once __DIR__ . '/recycle_bin.php';

// Everything else that can be removed from the system - uploads (and their
// stored file), schools, users and OCR course keywords - goes to
// recycle_bin_items first, as a JSON snapshot of the rows (graduates have their
// own table, see recycle_bin.php). Restore puts the rows back under their
// ORIGINAL ids; only "delete permanently" removes them for good (and deletes a
// binned file). Created by database/add_recycle_bin_items.sql on an existing
// database. Callers must also load includes/functions.php (paginate()).

const RECYCLE_BIN_TYPE_LABELS = [
    'record' => 'RECORD',
    'upload' => 'UPLOAD',
    'school' => 'SCHOOL',
    'user' => 'USER',
    'ocr_keyword' => 'KEYWORD',
];

/** Columns that may be written back on restore, per table (never trust stored keys blindly). */
const RECYCLE_BIN_RESTORE_COLUMNS = [
    'latin_honor_uploads' => ['id', 'school_id', 'school_year', 'original_filename', 'stored_filename', 'file_path', 'file_size', 'file_hash', 'uploaded_by', 'upload_number', 'extraction_status', 'records_extracted', 'total_pages', 'extracted_through_page', 'uploaded_at', 'updated_at'],
    'extraction_review_drafts' => ['upload_id', 'rows_json', 'saved_by', 'created_at', 'updated_at'],
    'schools' => ['id', 'school_name', 'address', 'campus', 'status', 'created_at', 'updated_at'],
    'users' => ['id', 'name', 'username', 'email', 'password', 'role', 'status', 'created_at', 'updated_at'],
    'ocr_course_keywords' => ['id', 'keyword', 'created_by', 'created_at'],
];

/** The friendly message when recycle_bin_items has not been created yet, else null. */
function recycle_bin_missing_table_message(\Throwable $e): ?string
{
    if ($e instanceof \PDOException && ($e->errorInfo[0] ?? '') === '42S02' && strpos($e->getMessage(), 'recycle_bin_items') !== false) {
        return 'THE RECYCLE BIN IS NOT SET UP ON THIS DATABASE YET. RUN database/add_recycle_bin_items.sql ONCE. NOTHING WAS REMOVED.';
    }

    return null;
}

/** Where binned files (an upload's PDF/template) are kept: storage/recycle_bin, closed to the web. */
function recycle_bin_dir(): string
{
    $dir = __DIR__ . '/../storage/recycle_bin';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $htaccess = $dir . '/.htaccess';
    if (is_dir($dir) && !is_file($htaccess)) {
        @file_put_contents($htaccess, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n");
    }

    return $dir;
}

/** Moves a file into the bin directory; returns its name there, or null when there is no file to move. */
function recycle_bin_move_file_in(string $path): ?string
{
    if ($path === '' || !is_file($path)) {
        return null;
    }
    $dir = recycle_bin_dir();
    $name = bin2hex(random_bytes(8)) . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', basename($path));
    if (!@rename($path, $dir . '/' . $name)) {
        throw new \RuntimeException('Could not move the file into the recycle bin.');
    }

    return $name;
}

function recycle_bin_move_file_back(string $binFile, string $destination): bool
{
    $source = recycle_bin_dir() . '/' . basename($binFile);
    if (!is_file($source) || file_exists($destination) || !is_dir(dirname($destination))) {
        return false;
    }

    return @rename($source, $destination);
}

/**
 * Snapshots one removed item into recycle_bin_items. Does NOT delete the
 * original and does NOT open a transaction - callers run it inside the same
 * transaction as their DELETE, so a failure leaves both untouched.
 */
function archive_item(PDO $pdo, string $type, int $originalId, string $label, string $details, array $payload, ?int $userId, string $reason, ?string $binFile = null): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO recycle_bin_items (item_type, original_id, label, details, payload, bin_file, deleted_by, deleted_at, deleted_reason)
         VALUES (:type, :original_id, :label, :details, :payload, :bin_file, :deleted_by, :deleted_at, :reason)'
    );
    $stmt->execute([
        'type' => $type,
        'original_id' => $originalId,
        'label' => mb_substr($label, 0, 255),
        'details' => mb_substr($details, 0, 500),
        'payload' => json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE),
        'bin_file' => $binFile,
        'deleted_by' => $userId,
        'deleted_at' => date('Y-m-d H:i:s'),
        'reason' => mb_substr($reason, 0, 500),
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * The bin page's list: graduates and every other item type, newest first.
 *
 * @param array{name?: string, type?: string, via?: string} $filters  'type' is a RECYCLE_BIN_TYPE_LABELS key; 'via' only applies to records
 * @return array{rows: array, pagination: array, notice: ?string}
 */
function get_recycle_bin_combined(PDO $pdo, array $filters, int $page, int $perPage = 25): array
{
    $type = (string) ($filters['type'] ?? '');
    $via = (string) ($filters['via'] ?? '');
    $name = trim((string) ($filters['name'] ?? ''));
    $notice = null;

    $recordSql = "SELECT 'record' AS kind, b.id AS bin_id, b.graduate_name AS title, b.course, b.latin_honor, b.graduation_year,
                         s.school_name, s.campus, '' AS details, b.deleted_at, u.name AS deleted_by_name, b.deleted_reason, b.deleted_via AS via
                  FROM graduate_recycle_bin b
                  LEFT JOIN schools s ON s.id = b.school_id
                  LEFT JOIN users u ON u.id = b.deleted_by";
    $itemSql = "SELECT i.item_type AS kind, i.id AS bin_id, i.label AS title, '' AS course, '' AS latin_honor, NULL AS graduation_year,
                       NULL AS school_name, NULL AS campus, i.details, i.deleted_at, u.name AS deleted_by_name, i.deleted_reason, 'manual' AS via
                FROM recycle_bin_items i
                LEFT JOIN users u ON u.id = i.deleted_by";

    $includeItems = ($type === '' || ($type !== 'record' && isset(RECYCLE_BIN_TYPE_LABELS[$type]))) && $via === '';
    $includeRecords = $type === '' || $type === 'record';

    $unions = [];
    if ($includeRecords) {
        $unions[] = $recordSql;
    }
    if ($includeItems) {
        $unions[] = $itemSql;
    }
    if ($unions === []) {
        return ['rows' => [], 'pagination' => paginate(0, $page, $perPage), 'notice' => null];
    }

    $conditions = [];
    $params = [];
    if ($name !== '') {
        $conditions[] = 't.title LIKE :name';
        $params['name'] = '%' . addcslashes($name, '\\%_') . '%';
    }
    if ($type !== '' && $type !== 'record') {
        $conditions[] = 't.kind = :type';
        $params['type'] = $type;
    }
    if (in_array($via, ['manual', 'duplicate_cleanup', 'review_edit'], true)) {
        $conditions[] = 't.via = :via';
        $params['via'] = $via;
    }
    $whereSql = $conditions === [] ? '' : ('WHERE ' . implode(' AND ', $conditions));

    $run = static function (array $unions) use ($pdo, $whereSql, $params, $page, $perPage): array {
        $from = '(' . implode(' UNION ALL ', $unions) . ') t';
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM {$from} {$whereSql}");
        $countStmt->execute($params);
        $pagination = paginate((int) $countStmt->fetchColumn(), $page, $perPage);

        $stmt = $pdo->prepare("SELECT t.* FROM {$from} {$whereSql} ORDER BY t.deleted_at DESC, t.kind ASC, t.bin_id DESC LIMIT :limit OFFSET :offset");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue('limit', $pagination['per_page'], PDO::PARAM_INT);
        $stmt->bindValue('offset', $pagination['offset'], PDO::PARAM_INT);
        $stmt->execute();

        return ['rows' => $stmt->fetchAll(), 'pagination' => $pagination];
    };

    try {
        $result = $run($unions);
    } catch (\PDOException $e) {
        // recycle_bin_items not created yet: still show the graduates, and say so.
        if (recycle_bin_missing_table_message($e) === null || !$includeRecords) {
            throw $e;
        }
        $notice = 'UPLOADS, SCHOOLS, USERS AND KEYWORDS CANNOT BE KEPT IN THE BIN YET. RUN database/add_recycle_bin_items.sql ONCE.';
        $result = $run([$recordSql]);
    }

    return $result + ['notice' => $notice];
}

/** @param array<string, mixed> $row */
function recycle_bin_insert_row(PDO $pdo, string $table, array $row): void
{
    $columns = array_values(array_intersect(array_keys($row), RECYCLE_BIN_RESTORE_COLUMNS[$table]));
    $stmt = $pdo->prepare(
        'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)) . ')'
    );
    $stmt->execute(array_intersect_key($row, array_flip($columns)));
}

function recycle_bin_exists(PDO $pdo, string $sql, array $params): bool
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchColumn() !== false;
}

/**
 * Puts a binned upload / school / user / keyword back under its original id.
 * Refuses with a readable error when it would clash with something that exists now.
 *
 * @return array{ok: bool, error?: string, type?: string, label?: string}
 */
function restore_recycle_item(PDO $pdo, int $binId, ?int $restoredBy = null): array
{
    $stmt = $pdo->prepare('SELECT * FROM recycle_bin_items WHERE id = :id');
    $stmt->execute(['id' => $binId]);
    $item = $stmt->fetch();
    if ($item === false) {
        return ['ok' => false, 'error' => 'RECYCLE BIN ITEM NOT FOUND.'];
    }

    $payload = json_decode((string) $item['payload'], true);
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'THIS ITEM\'S SAVED DATA IS DAMAGED AND CANNOT BE RESTORED.'];
    }

    $id = (int) $item['original_id'];
    $movedBack = false;
    $destination = '';

    try {
        switch ($item['item_type']) {
            case 'upload':
                $upload = $payload['upload'] ?? [];
                if (recycle_bin_exists($pdo, 'SELECT 1 FROM latin_honor_uploads WHERE id = :id', ['id' => $id])) {
                    return ['ok' => false, 'error' => 'AN UPLOAD WITH THIS ID ALREADY EXISTS AND THIS ITEM CANNOT BE RESTORED.'];
                }
                if (!recycle_bin_exists($pdo, 'SELECT 1 FROM schools WHERE id = :id', ['id' => $upload['school_id'] ?? 0])
                    || !recycle_bin_exists($pdo, 'SELECT 1 FROM users WHERE id = :id', ['id' => $upload['uploaded_by'] ?? 0])) {
                    return ['ok' => false, 'error' => 'THE UPLOAD\'S SCHOOL OR UPLOADER NO LONGER EXISTS, SO IT CANNOT BE RESTORED.'];
                }
                if (recycle_bin_exists($pdo, 'SELECT 1 FROM latin_honor_uploads WHERE school_id = :s AND school_year = :y AND upload_number = :n', ['s' => $upload['school_id'], 'y' => $upload['school_year'], 'n' => $upload['upload_number']])) {
                    return ['ok' => false, 'error' => 'ANOTHER UPLOAD NOW USES THIS SCHOOL, YEAR AND UPLOAD NUMBER, SO THIS ONE CANNOT BE RESTORED.'];
                }
                if ($item['bin_file'] !== null) {
                    $destination = (string) ($upload['file_path'] ?? '');
                    if ($destination === '' || !recycle_bin_move_file_back((string) $item['bin_file'], $destination)) {
                        return ['ok' => false, 'error' => 'THE UPLOADED FILE COULD NOT BE PUT BACK (IT IS MISSING OR A FILE ALREADY EXISTS AT ITS OLD LOCATION).'];
                    }
                    $movedBack = true;
                }
                $pdo->beginTransaction();
                recycle_bin_insert_row($pdo, 'latin_honor_uploads', $upload);
                if (!empty($payload['draft'])) {
                    recycle_bin_insert_row($pdo, 'extraction_review_drafts', $payload['draft']);
                }
                break;

            case 'school':
                $school = $payload['school'] ?? [];
                if (recycle_bin_exists($pdo, 'SELECT 1 FROM schools WHERE id = :id OR (school_name = :n AND campus = :c)', ['id' => $id, 'n' => $school['school_name'] ?? '', 'c' => $school['campus'] ?? ''])) {
                    return ['ok' => false, 'error' => 'A SCHOOL WITH THIS ID OR NAME AND CAMPUS ALREADY EXISTS, SO THIS ONE CANNOT BE RESTORED.'];
                }
                $pdo->beginTransaction();
                recycle_bin_insert_row($pdo, 'schools', $school);
                break;

            case 'user':
                $user = $payload['user'] ?? [];
                if (recycle_bin_exists($pdo, 'SELECT 1 FROM users WHERE id = :id OR username = :u OR email = :e', ['id' => $id, 'u' => $user['username'] ?? '', 'e' => $user['email'] ?? ''])) {
                    return ['ok' => false, 'error' => 'A USER WITH THIS ID, USERNAME OR EMAIL ALREADY EXISTS, SO THIS ONE CANNOT BE RESTORED.'];
                }
                $pdo->beginTransaction();
                recycle_bin_insert_row($pdo, 'users', $user);
                // Hand the account's activity-log entries back (removal anonymized them).
                foreach (array_chunk(array_map('intval', (array) ($payload['activity_ids'] ?? [])), 1000) as $chunk) {
                    $pdo->prepare('UPDATE activity_log SET user_id = ? WHERE user_id IS NULL AND id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')')
                        ->execute(array_merge([$id], $chunk));
                }
                break;

            case 'ocr_keyword':
                $keyword = $payload['keyword'] ?? [];
                if (recycle_bin_exists($pdo, 'SELECT 1 FROM ocr_course_keywords WHERE id = :id OR keyword = :k', ['id' => $id, 'k' => $keyword['keyword'] ?? ''])) {
                    return ['ok' => false, 'error' => 'THIS KEYWORD ALREADY EXISTS, SO IT CANNOT BE RESTORED.'];
                }
                if (!recycle_bin_exists($pdo, 'SELECT 1 FROM users WHERE id = :id', ['id' => $keyword['created_by'] ?? 0]) && $restoredBy !== null) {
                    $keyword['created_by'] = $restoredBy;
                }
                $pdo->beginTransaction();
                recycle_bin_insert_row($pdo, 'ocr_course_keywords', $keyword);
                break;

            default:
                return ['ok' => false, 'error' => 'UNKNOWN RECYCLE BIN ITEM TYPE.'];
        }

        $pdo->prepare('DELETE FROM recycle_bin_items WHERE id = :id')->execute(['id' => $binId]);
        $pdo->commit();
    } catch (\Throwable $e) {
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (\Throwable $rollbackError) {
            error_log('restore_recycle_item rollback failed: ' . $rollbackError->getMessage());
        }
        if ($movedBack) {
            @rename($destination, recycle_bin_dir() . '/' . basename((string) $item['bin_file']));
        }
        error_log('restore_recycle_item failed: ' . $e->getMessage());

        return ['ok' => false, 'error' => 'THE ITEM COULD NOT BE RESTORED. PLEASE TRY AGAIN.'];
    }

    return ['ok' => true, 'type' => (string) $item['item_type'], 'label' => (string) $item['label']];
}

/**
 * Permanently deletes one non-graduate bin item (and its binned file).
 *
 * @return array{type: string, label: string}|null  null when it does not exist
 */
function purge_recycle_item(PDO $pdo, int $binId): ?array
{
    $stmt = $pdo->prepare('SELECT item_type, label, bin_file FROM recycle_bin_items WHERE id = :id');
    $stmt->execute(['id' => $binId]);
    $item = $stmt->fetch();
    if ($item === false) {
        return null;
    }

    $pdo->prepare('DELETE FROM recycle_bin_items WHERE id = :id')->execute(['id' => $binId]);
    if ($item['bin_file'] !== null) {
        @unlink(recycle_bin_dir() . '/' . basename((string) $item['bin_file']));
    }

    return ['type' => (string) $item['item_type'], 'label' => (string) $item['label']];
}
