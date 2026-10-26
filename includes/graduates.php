<?php

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/recycle_bin.php';
require_once __DIR__ . '/duplicates.php';

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

    // "Needs action" — filed but still awaiting approval (admin/lookup.php's
    // NEEDS ACTION button). Requires the same LEFT JOIN graduate_applications
    // the main SELECT uses, so it has to be added to the count query too.
    if (($filters['status'] ?? '') === 'pending') {
        $conditions[] = 'a.id IS NOT NULL AND a.approved_at IS NULL';
    }

    // Drill-down from admin/dashboard.php's "HGE APPLICATIONS RECEIVED" card:
    // applications FILED in this calendar year, regardless of the
    // applicant's own graduation_year — matches
    // includes/functions.php:get_applied_count_by_year() exactly, so the
    // row count here always equals that card's number. Deliberately NOT the
    // same scope as graduation_year above (see includes/reports.php's
    // get_application_filing_report() vs. get_applied_breakdown_by_filed_year()
    // docblocks for why these two scopes are kept distinct).
    if (!empty($filters['applied_year'])) {
        $conditions[] = 'YEAR(a.application_date) = :applied_year';
        $params['applied_year'] = (int) $filters['applied_year'];
    }

    $whereSql = empty($conditions) ? '' : ('WHERE ' . implode(' AND ', $conditions));

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM latin_honor_graduates g
         LEFT JOIN graduate_applications a ON a.graduate_id = g.id
         {$whereSql}"
    );
    $countStmt->execute($params);
    $totalRows = (int) $countStmt->fetchColumn();

    $pagination = paginate($totalRows, $page, $perPage);

    $sql = "SELECT g.id, g.graduate_name, g.course, g.latin_honor, g.graduation_date, g.graduation_year, g.source,
                   s.school_name, s.campus, u.id AS upload_id, u.original_filename, u.uploaded_at,
                   a.application_date, (a.id IS NOT NULL) AS has_applied,
                   a.approved_at, (a.approved_at IS NOT NULL) AS has_approved
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

    $rows = $stmt->fetchAll();
    $duplicateIds = flag_duplicates_for_rows($pdo, array_column($rows, 'id'));
    foreach ($rows as &$row) {
        $row['is_duplicate'] = in_array((int) $row['id'], $duplicateIds, true) ? 1 : 0;
    }
    unset($row);

    return ['rows' => $rows, 'pagination' => $pagination];
}

function get_graduate_by_id(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, upload_id, school_id, graduate_name, course, latin_honor, graduation_date, graduation_year, source
         FROM latin_honor_graduates WHERE id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

/**
 * Edits a saved record directly (the admin/lookup.php "edit" action, outside
 * the review flow). Always stamps source = 'manual' — the moment an admin
 * corrects a saved record, it's no longer purely AI/OCR/text output, same
 * as set_staged_row() already does when a row is edited during review.
 */
function update_graduate(PDO $pdo, int $id, array $data): void
{
    $stmt = $pdo->prepare(
        'UPDATE latin_honor_graduates
         SET graduate_name = :graduate_name, course = :course, latin_honor = :latin_honor,
             graduation_date = :graduation_date, graduation_year = :graduation_year, source = \'manual\'
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
 * Pure. The year a graduate finished: the year of the graduation date when there is one, otherwise
 * the stored graduation year.
 */
function graduate_effective_graduation_year(?string $graduationDate, int $graduationYear): int
{
    if ($graduationDate !== null && preg_match('/^(\d{4})-/', $graduationDate, $m) === 1) {
        return (int) $m[1];
    }

    return $graduationYear;
}

/**
 * Pure. The rule for the date applied and the date approved: its year may not be before the year the
 * graduate graduated (nobody applies for a Latin Honor before earning it). Returns the message to show
 * the user, or null when the date is acceptable.
 */
function application_date_year_error(int $graduationYear, string $date, string $label): ?string
{
    $year = (int) substr($date, 0, 4);
    if ($year >= $graduationYear) {
        return null;
    }

    return 'THE ' . $label . ' (' . $year . ') IS INVALID: IT IS BEFORE THE YEAR THE APPLICANT GRADUATED (' . $graduationYear . '). PLEASE ENTER A DATE IN ' . $graduationYear . ' OR LATER.';
}

/**
 * Applications whose date applied or date approved falls in a year before the graduate's graduation
 * year. Read-only.
 *
 * @return array<int, array<string, mixed>>
 */
function find_invalid_application_dates(PDO $pdo): array
{
    return $pdo->query(
        'SELECT a.id AS application_id, a.graduate_id, g.graduate_name, g.upload_id, g.school_id,
                g.graduation_date, g.graduation_year,
                COALESCE(YEAR(g.graduation_date), g.graduation_year) AS effective_year,
                a.application_date, a.approved_at, a.applied_by, a.approved_by
         FROM graduate_applications a
         JOIN latin_honor_graduates g ON g.id = a.graduate_id
         WHERE YEAR(a.application_date) < COALESCE(YEAR(g.graduation_date), g.graduation_year)
            OR (a.approved_at IS NOT NULL AND YEAR(a.approved_at) < COALESCE(YEAR(g.graduation_date), g.graduation_year))
         ORDER BY a.id'
    )->fetchAll();
}

/**
 * Removes the date applied and date approved of every application that breaks the rule above.
 * application_date is NOT NULL, so removing the dates means removing the application row: the graduate
 * goes back to "not applied". Returns the removed rows (as find_invalid_application_dates() listed them).
 *
 * @return array<int, array<string, mixed>>
 */
function remove_invalid_application_dates(PDO $pdo): array
{
    $pdo->beginTransaction();
    try {
        $invalid = find_invalid_application_dates($pdo);
        $delete = $pdo->prepare('DELETE FROM graduate_applications WHERE id = :id');
        foreach ($invalid as $row) {
            $delete->execute(['id' => $row['application_id']]);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $invalid;
}

/**
 * @return array{ok: bool, error?: string}
 */
function file_application(PDO $pdo, int $graduateId, int $appliedBy, string $applicationDate): array
{
    $graduate = get_graduate_by_id($pdo, $graduateId);
    if ($graduate === null) {
        return ['ok' => false, 'error' => 'GRADUATE RECORD NOT FOUND.'];
    }
    $yearError = application_date_year_error(
        graduate_effective_graduation_year($graduate['graduation_date'], (int) $graduate['graduation_year']),
        $applicationDate,
        'DATE OF APPLICATION'
    );
    if ($yearError !== null) {
        return ['ok' => false, 'error' => $yearError];
    }
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
 * Marks an already-filed application as approved (the admin/lookup.php
 * "mark approved" action, administrator-only — see api/approve_application.php).
 * Requires a graduate_applications row to already exist; approval never files
 * on its own, it only adds an approved_at/approved_by to an existing filing.
 *
 * @return array{ok: bool, error?: string}
 */
function approve_application(PDO $pdo, int $graduateId, int $approvedBy, string $approvalDate): array
{
    // Checked up front (PDO's rowCount() for an UPDATE reports rows actually
    // changed, not rows matched — re-approving with the exact same date would
    // otherwise look identical to "no application exists" and surface the
    // wrong error message).
    $exists = $pdo->prepare('SELECT 1 FROM graduate_applications WHERE graduate_id = :graduate_id LIMIT 1');
    $exists->execute(['graduate_id' => $graduateId]);
    if ($exists->fetchColumn() === false) {
        return ['ok' => false, 'error' => 'THIS GRADUATE HAS NOT FILED AN APPLICATION YET.'];
    }

    $graduate = get_graduate_by_id($pdo, $graduateId);
    if ($graduate !== null) {
        $yearError = application_date_year_error(
            graduate_effective_graduation_year($graduate['graduation_date'], (int) $graduate['graduation_year']),
            $approvalDate,
            'DATE OF APPROVAL'
        );
        if ($yearError !== null) {
            return ['ok' => false, 'error' => $yearError];
        }
    }

    $pdo->prepare(
        'UPDATE graduate_applications
         SET approved_at = :approved_at, approved_by = :approved_by
         WHERE graduate_id = :graduate_id'
    )->execute([
        'approved_at' => $approvalDate,
        'approved_by' => $approvedBy,
        'graduate_id' => $graduateId,
    ]);

    return ['ok' => true];
}

/**
 * Undoes the most recent step recorded for a graduate's HGE application (the
 * admin/lookup.php "revert" action — see api/revert_application.php):
 *
 *  - Already approved: clears the approval only (approved_at/approved_by ->
 *    NULL) and requires $isAdmin — the filing itself is left alone, so the
 *    record goes back to PROCESSING with its original application_date
 *    intact, same as it was before it was approved.
 *  - Filed but not approved: removes the filing entirely. application_date
 *    is NOT NULL in the schema, so there is no "cleared but still filed"
 *    state to revert to — the only way back to "not yet applied" is
 *    deleting the row. Open to any user, mirroring file_application()'s own
 *    permission level (the opposite of filing is available to whoever can
 *    file).
 *
 * @return array{ok: bool, reverted?: string, error?: string} reverted is
 *     'approval' or 'application'
 */
function revert_application(PDO $pdo, int $graduateId, bool $isAdmin): array
{
    $stmt = $pdo->prepare('SELECT approved_at FROM graduate_applications WHERE graduate_id = :graduate_id');
    $stmt->execute(['graduate_id' => $graduateId]);
    $row = $stmt->fetch();

    if ($row === false) {
        return ['ok' => false, 'error' => 'THIS GRADUATE HAS NOT FILED AN APPLICATION.'];
    }

    if ($row['approved_at'] !== null) {
        if (!$isAdmin) {
            return ['ok' => false, 'error' => 'ADMINISTRATOR ACCESS IS REQUIRED TO REVERT AN APPROVAL.'];
        }

        $pdo->prepare('UPDATE graduate_applications SET approved_at = NULL, approved_by = NULL WHERE graduate_id = :graduate_id')
            ->execute(['graduate_id' => $graduateId]);

        return ['ok' => true, 'reverted' => 'approval'];
    }

    $pdo->prepare('DELETE FROM graduate_applications WHERE graduate_id = :graduate_id')
        ->execute(['graduate_id' => $graduateId]);

    return ['ok' => true, 'reverted' => 'application'];
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
function delete_graduate(PDO $pdo, int $id, ?int $userId = null, string $reason = '', string $via = 'manual'): array
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

        archive_graduate($pdo, $id, $userId, $reason, $via);
        $pdo->prepare('DELETE FROM latin_honor_graduates WHERE id = :id')->execute(['id' => $id]);
        $pdo->prepare('UPDATE latin_honor_uploads SET records_extracted = IF(records_extracted > 0, records_extracted - 1, 0) WHERE id = :upload_id')
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
 * Saved graduates that are NAME duplicates (see duplicate_group_key()) of the
 * rows in a review, wherever they are saved - any school, any course, any
 * graduation year - from any upload except the one under review (its own saved
 * rows are what the review replaces). Used by admin/review.php's badge and by
 * the SAVE RECORDS prompt: the same person is often on two schools' lists
 * (another campus, or a list uploaded under the wrong school). A hint for a
 * human to decide, not an automatic dedup.
 *
 * A copy that already has a filed application PREVAILS, so within each name the
 * applied copies come first (then oldest first), and carry has_applied /
 * applied_on so the caller can say so.
 *
 * Callers must also load includes/functions.php and includes/duplicates.php.
 *
 * @return array<string, array<int, array{graduate_name: string, course: string, latin_honor: string, graduation_year: int, school: string, original_filename: string, uploaded_at: string, has_applied: bool, applied_on: ?string}>>
 *     Keyed by duplicate_group_key(); at most $perKey matches per key.
 */
function find_saved_duplicates_by_name(PDO $pdo, int $excludeUploadId, int $perKey = 5): array
{
    $stmt = $pdo->prepare(
        'SELECT g.graduate_name, g.course, g.latin_honor, g.graduation_year, s.school_name, s.campus, u.original_filename, u.uploaded_at,
                (a.id IS NOT NULL) AS has_applied, a.application_date
         FROM latin_honor_graduates g
         JOIN latin_honor_uploads u ON u.id = g.upload_id
         LEFT JOIN schools s ON s.id = g.school_id
         LEFT JOIN graduate_applications a ON a.graduate_id = g.id
         WHERE g.upload_id <> :exclude_upload_id
         ORDER BY (a.id IS NOT NULL) DESC, g.id ASC'
    );
    $stmt->execute(['exclude_upload_id' => $excludeUploadId]);

    $byKey = [];
    foreach ($stmt->fetchAll() as $row) {
        if (normalize_name_for_matching((string) $row['graduate_name']) === '') {
            continue;
        }
        $key = duplicate_group_key($row);
        if (count($byKey[$key] ?? []) >= $perKey) {
            continue;
        }
        $byKey[$key][] = [
            'graduate_name' => (string) $row['graduate_name'],
            'course' => (string) $row['course'],
            'latin_honor' => (string) $row['latin_honor'],
            'graduation_year' => (int) $row['graduation_year'],
            'school' => $row['school_name'] !== null ? format_school_display_name((string) $row['school_name'], $row['campus'] ?? null) : '',
            'original_filename' => (string) $row['original_filename'],
            'uploaded_at' => (string) $row['uploaded_at'],
            'has_applied' => (int) $row['has_applied'] === 1,
            'applied_on' => $row['application_date'] !== null ? (string) $row['application_date'] : null,
        ];
    }

    return $byKey;
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

    if (($filters['status'] ?? '') === 'pending') {
        $parts[] = 'status=pending';
    }

    if (!empty($filters['applied_year'])) {
        $parts[] = 'applied_year=' . (int) $filters['applied_year'];
    }

    return implode(', ', $parts);
}
