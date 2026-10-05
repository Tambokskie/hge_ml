<?php

require_once __DIR__ . '/functions.php';

function search_graduates(PDO $pdo, array $filters, int $page, int $perPage = 25): array
{
    $conditions = [];
    $params = [];

    if (!empty($filters['school_id'])) {
        $conditions[] = 'g.school_id = :school_id';
        $params['school_id'] = (int) $filters['school_id'];
    }

    if (!empty($filters['graduation_year'])) {
        $conditions[] = 'g.graduation_year = :graduation_year';
        $params['graduation_year'] = (int) $filters['graduation_year'];
    }

    $name = trim((string) ($filters['name'] ?? ''));
    if ($name !== '') {
        $conditions[] = 'g.graduate_name LIKE :name';
        $params['name'] = '%' . addcslashes($name, '\\%_') . '%';
    }

    $whereSql = empty($conditions) ? '' : ('WHERE ' . implode(' AND ', $conditions));

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM latin_honor_graduates g {$whereSql}");
    $countStmt->execute($params);
    $totalRows = (int) $countStmt->fetchColumn();

    $pagination = paginate($totalRows, $page, $perPage);

    $sql = "SELECT g.id, g.graduate_name, g.course, g.latin_honor, g.graduation_date, g.graduation_year, g.source,
                   s.school_name, s.campus, u.id AS upload_id, u.original_filename, u.uploaded_at,
                   a.application_date, (a.id IS NOT NULL) AS has_applied
            FROM latin_honor_graduates g
            JOIN schools s ON s.id = g.school_id
            JOIN latin_honor_uploads u ON u.id = g.upload_id
            LEFT JOIN graduate_applications a ON a.graduate_id = g.id
            {$whereSql}
            ORDER BY g.graduate_name ASC
            LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue('limit', $pagination['per_page'], PDO::PARAM_INT);
    $stmt->bindValue('offset', $pagination['offset'], PDO::PARAM_INT);
    $stmt->execute();

    return ['rows' => $stmt->fetchAll(), 'pagination' => $pagination];
}

function get_graduate_by_id(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, upload_id, school_id, graduate_name, course, latin_honor, graduation_date, graduation_year
         FROM latin_honor_graduates WHERE id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function update_graduate(PDO $pdo, int $id, array $data): void
{
    $stmt = $pdo->prepare(
        'UPDATE latin_honor_graduates
         SET graduate_name = :graduate_name, course = :course, latin_honor = :latin_honor,
             graduation_date = :graduation_date, graduation_year = :graduation_year
         WHERE id = :id'
    );
    $stmt->execute([
        'graduate_name' => $data['graduate_name'],
        'course' => $data['course'],
        'latin_honor' => $data['latin_honor'],
        'graduation_date' => $data['graduation_date'],
        'graduation_year' => $data['graduation_year'],
        'id' => $id,
    ]);
}

/**
 * @return array{ok: bool, error?: string}
 */
function file_application(PDO $pdo, int $graduateId, int $appliedBy, string $applicationDate): array
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO graduate_applications (graduate_id, applied_by, application_date)
             VALUES (:graduate_id, :applied_by, :application_date)'
        );
        $stmt->execute([
            'graduate_id' => $graduateId,
            'applied_by' => $appliedBy,
            'application_date' => $applicationDate,
        ]);
    } catch (\PDOException $e) {
        if ((int) $e->getCode() === 23000) {
            return ['ok' => false, 'error' => 'THIS GRADUATE HAS ALREADY FILED AN APPLICATION.'];
        }
        throw $e;
    }

    return ['ok' => true];
}

/**
 * Existing saved records for one upload, shaped like a staged review row
 * (plus the DB id) so they can be dropped straight into an extraction
 * review session — this is what lets an already-reviewed upload be
 * reopened in the review screen to add a missed entry or fix a mistake.
 */
function get_graduates_for_upload(PDO $pdo, int $uploadId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, graduate_name, course, latin_honor, graduation_date, graduation_year, source
         FROM latin_honor_graduates WHERE upload_id = :upload_id ORDER BY graduate_name ASC'
    );
    $stmt->execute(['upload_id' => $uploadId]);

    return array_map(static function (array $row): array {
        $row['id'] = (int) $row['id'];
        $row['graduation_year'] = (int) $row['graduation_year'];

        return $row;
    }, $stmt->fetchAll());
}

/**
 * @return array{ok: bool, error?: string}
 */
function delete_graduate(PDO $pdo, int $id): array
{
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT upload_id FROM latin_honor_graduates WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $uploadId = $stmt->fetchColumn();

        if ($uploadId === false) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'GRADUATE RECORD NOT FOUND.'];
        }

        $pdo->prepare('DELETE FROM latin_honor_graduates WHERE id = :id')->execute(['id' => $id]);
        $pdo->prepare('UPDATE latin_honor_uploads SET records_extracted = GREATEST(records_extracted - 1, 0) WHERE id = :upload_id')
            ->execute(['upload_id' => $uploadId]);

        $pdo->commit();
    } catch (\Throwable $e) {
        try {
            $pdo->rollBack();
        } catch (\Throwable $rollbackError) {
            error_log('delete_graduate rollback failed: ' . $rollbackError->getMessage());
        }

        if ($e instanceof \PDOException && (int) $e->getCode() === 23000) {
            return ['ok' => false, 'error' => 'THIS GRADUATE HAS A FILED APPLICATION AND CANNOT BE DELETED.'];
        }

        error_log('delete_graduate failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'THE RECORD COULD NOT BE DELETED. PLEASE TRY AGAIN.'];
    }

    return ['ok' => true];
}

function get_graduation_years_for_school(PDO $pdo, int $schoolId): array
{
    $stmt = $pdo->prepare(
        'SELECT DISTINCT graduation_year FROM latin_honor_graduates WHERE school_id = :school_id ORDER BY graduation_year DESC'
    );
    $stmt->execute(['school_id' => $schoolId]);

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Bulk-fills a missing date of graduation across every honor graduate from
 * one school/year at once — the workaround for lists whose PDF never
 * carried a date column, so extraction had nothing to parse it from.
 */
function bulk_update_graduation_date(PDO $pdo, int $schoolId, int $year, string $date): array
{
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        return ['status' => 'error', 'message' => 'DATE OF GRADUATION MUST BE A VALID DATE.'];
    }

    $stmt = $pdo->prepare(
        'UPDATE latin_honor_graduates SET graduation_date = :date WHERE school_id = :school_id AND graduation_year = :year'
    );
    $stmt->execute(['date' => $date, 'school_id' => $schoolId, 'year' => $year]);

    $updated = $stmt->rowCount();
    if ($updated === 0) {
        return ['status' => 'error', 'message' => 'NO HONOR GRADUATE RECORDS WERE FOUND FOR THIS SCHOOL AND YEAR.'];
    }

    return ['status' => 'success', 'message' => $updated . ' RECORD(S) UPDATED.', 'updated' => $updated];
}

/**
 * Looks up already-saved graduates for the same school+year (from any OTHER
 * upload — a re-upload for a school+year that already has reviewed records,
 * see handle_upload_request()'s duplicate-upload confirmation) so
 * admin/review.php can flag a staged row that likely already exists rather
 * than letting the same person silently get inserted twice under two
 * different upload_ids.
 *
 * Matching is by normalized graduate name only (see
 * normalize_name_for_matching() — tolerant of case/whitespace/punctuation
 * differences between two independent extractions of the same list); course
 * and latin_honor are returned alongside so the caller can tell a same-name
 * exact match apart from a same-name-different-details one, without this
 * function silently deciding which is "more likely" a real duplicate.
 *
 * @return array<string, array{graduate_name: string, course: string, latin_honor: string, upload_id: int, original_filename: string, uploaded_at: string}>
 *     Keyed by normalize_name_for_matching() output. A name matching more
 *     than one existing record (rare — e.g. two different graduates who
 *     happen to share a name) keeps only the most recent upload's row; this
 *     is a review-time hint for a human to double-check, not an authoritative
 *     lookup, so picking one is an acceptable simplification.
 */
function find_possible_duplicate_graduates(PDO $pdo, int $schoolId, string $schoolYear, int $excludeUploadId): array
{
    $stmt = $pdo->prepare(
        'SELECT g.graduate_name, g.course, g.latin_honor, u.id AS upload_id, u.original_filename, u.uploaded_at
         FROM latin_honor_graduates g
         JOIN latin_honor_uploads u ON u.id = g.upload_id
         WHERE u.school_id = :school_id AND u.school_year = :school_year AND u.id != :exclude_upload_id
         ORDER BY u.uploaded_at ASC'
    );
    $stmt->execute([
        'school_id' => $schoolId,
        'school_year' => $schoolYear,
        'exclude_upload_id' => $excludeUploadId,
    ]);

    $existing = [];
    foreach ($stmt->fetchAll() as $row) {
        $key = normalize_name_for_matching($row['graduate_name']);
        if ($key === '') {
            continue;
        }
        $existing[$key] = [
            'graduate_name' => $row['graduate_name'],
            'course' => $row['course'],
            'latin_honor' => $row['latin_honor'],
            'upload_id' => (int) $row['upload_id'],
            'original_filename' => $row['original_filename'],
            'uploaded_at' => $row['uploaded_at'],
        ];
    }

    return $existing;
}

function build_search_log_details(array $filters): string
{
    $parts = [];

    if (!empty($filters['school_id'])) {
        $parts[] = 'school_id=' . (int) $filters['school_id'];
    }

    if (!empty($filters['graduation_year'])) {
        $parts[] = 'year=' . (int) $filters['graduation_year'];
    }

    $name = trim((string) ($filters['name'] ?? ''));
    if ($name !== '') {
        $parts[] = 'name=' . $name;
    }

    return implode(', ', $parts);
}
