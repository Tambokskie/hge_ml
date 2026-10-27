<?php

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/recycle_bin_items.php';
require_once __DIR__ . '/pdf_files.php';

function find_existing_upload(PDO $pdo, int $schoolId, string $schoolYear): ?array
{
    $stmt = $pdo->prepare(
        'SELECT u.id, u.school_id, u.school_year, u.original_filename, u.stored_filename,
                u.uploaded_at, u.records_extracted, s.school_name
         FROM latin_honor_uploads u
         JOIN schools s ON s.id = u.school_id
         WHERE u.school_id = :school_id AND u.school_year = :school_year
         ORDER BY u.upload_number DESC
         LIMIT 1'
    );
    $stmt->execute(['school_id' => $schoolId, 'school_year' => $schoolYear]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function next_upload_number(PDO $pdo, int $schoolId, string $schoolYear): int
{
    $stmt = $pdo->prepare(
        'SELECT COALESCE(MAX(upload_number), 0) FROM latin_honor_uploads WHERE school_id = :school_id AND school_year = :school_year'
    );
    $stmt->execute(['school_id' => $schoolId, 'school_year' => $schoolYear]);

    return ((int) $stmt->fetchColumn()) + 1;
}

function create_upload_record(PDO $pdo, array $data): array
{
    $stmt = $pdo->prepare(
        'INSERT INTO latin_honor_uploads
            (school_id, school_year, original_filename, stored_filename, file_path, file_size, file_hash, uploaded_by, upload_number)
         VALUES
            (:school_id, :school_year, :original_filename, :stored_filename, :file_path, :file_size, :file_hash, :uploaded_by, :upload_number)'
    );
    $stmt->execute([
        'school_id' => $data['school_id'],
        'school_year' => $data['school_year'],
        'original_filename' => $data['original_filename'],
        'stored_filename' => $data['stored_filename'],
        'file_path' => $data['file_path'],
        'file_size' => $data['file_size'],
        'file_hash' => $data['file_hash'],
        'uploaded_by' => $data['uploaded_by'],
        'upload_number' => $data['upload_number'],
    ]);

    return [
        'id' => (int) $pdo->lastInsertId(),
        'school_id' => $data['school_id'],
        'school_year' => $data['school_year'],
        'original_filename' => $data['original_filename'],
        'stored_filename' => $data['stored_filename'],
        'file_path' => $data['file_path'],
        'file_size' => $data['file_size'],
        'file_hash' => $data['file_hash'],
        'uploaded_by' => $data['uploaded_by'],
        'upload_number' => $data['upload_number'],
        'extraction_status' => 'pending',
        'records_extracted' => 0,
    ];
}

function get_recent_uploads(PDO $pdo, int $limit = 5): array
{
    $stmt = $pdo->prepare(
        'SELECT u.id, u.school_year, u.original_filename, u.stored_filename, u.uploaded_at, u.records_extracted, s.school_name, s.campus
         FROM latin_honor_uploads u
         JOIN schools s ON s.id = u.school_id
         ORDER BY (u.records_extracted = 0) DESC,
                  CASE WHEN u.records_extracted = 0 THEN u.uploaded_at END ASC,
                  u.uploaded_at DESC, u.id DESC
         LIMIT :limit'
    );
    $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

/**
 * Filterable, paginated upload listing for the SCHOOL RECORDS page — the
 * same shape of query as search_graduates() in includes/graduates.php, but
 * grouped at the upload/PDF level instead of the individual-graduate level
 * so an admin can find and reopen the PDF a record actually lives in.
 *
 * Sort mirrors get_recent_uploads()'s pending-first tiering: not-yet-reviewed
 * uploads (the ones the STATUS column shows an "AGING" line for) surface
 * above reviewed ones, oldest-uploaded (highest aging) first within that
 * group, so the most neglected pending item is always the very first row.
 */
function search_uploads(PDO $pdo, array $filters, int $page, int $perPage = 20): array
{
    $conditions = [];
    $params = [];

    if (!empty($filters['school_id'])) {
        $conditions[] = 'u.school_id = :school_id';
        $params['school_id'] = (int) $filters['school_id'];
    }

    if (!empty($filters['school_year'])) {
        $conditions[] = 'u.school_year = :school_year';
        $params['school_year'] = (string) $filters['school_year'];
    }

    $whereSql = empty($conditions) ? '' : ('WHERE ' . implode(' AND ', $conditions));

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM latin_honor_uploads u JOIN schools s ON s.id = u.school_id {$whereSql}");
    $countStmt->execute($params);
    $totalRows = (int) $countStmt->fetchColumn();

    $pagination = paginate($totalRows, $page, $perPage);

    $sql = "SELECT u.id, u.school_year, u.original_filename, u.uploaded_at, u.updated_at, u.records_extracted,
                   u.extraction_status, s.school_name, s.campus
            FROM latin_honor_uploads u
            JOIN schools s ON s.id = u.school_id
            {$whereSql}
            ORDER BY (u.extraction_status != 'reviewed') DESC,
                     CASE WHEN u.extraction_status != 'reviewed' THEN u.uploaded_at END ASC,
                     u.uploaded_at DESC, u.id DESC
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

function get_distinct_upload_school_years(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT DISTINCT school_year FROM latin_honor_uploads ORDER BY school_year DESC');

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Counts every upload as either "completed" (reviewed — its records are
 * saved and official) or "pending" (anything still needing admin action:
 * pending, extracted, draft, or failed), for the SCHOOL RECORDS page's
 * Actions summary.
 */
function get_upload_action_summary(PDO $pdo): array
{
    $completed = (int) $pdo->query("SELECT COUNT(*) FROM latin_honor_uploads WHERE extraction_status = 'reviewed'")->fetchColumn();
    $pending = (int) $pdo->query("SELECT COUNT(*) FROM latin_honor_uploads WHERE extraction_status != 'reviewed'")->fetchColumn();

    return ['completed' => $completed, 'pending' => $pending];
}

function get_upload_by_id(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT u.id, u.school_id, u.school_year, u.original_filename, u.stored_filename, u.file_path,
                u.file_size, u.uploaded_at, u.extraction_status, u.records_extracted,
                u.total_pages, u.extracted_through_page, s.school_name, s.campus,
                usr.name AS uploaded_by_name
         FROM latin_honor_uploads u
         JOIN schools s ON s.id = u.school_id
         LEFT JOIN users usr ON usr.id = u.uploaded_by
         WHERE u.id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function handle_upload_request(PDO $pdo, array $post, array $files, int $uploadedBy, string $storageDir, int $maxBytes = 20971520): array
{
    $schoolId = (int) ($post['school_id'] ?? 0);
    $schoolName = trim((string) ($post['school_name'] ?? ''));
    $schoolYear = trim((string) ($post['school_year'] ?? ''));
    $confirmDuplicate = !empty($post['confirm_duplicate']);

    if (($schoolId <= 0 && $schoolName === '') || $schoolYear === '') {
        return ['status' => 'error', 'message' => 'SCHOOL NAME AND SCHOOL YEAR ARE REQUIRED.'];
    }

    if ($schoolId <= 0 && mb_strlen($schoolName) > 255) {
        return ['status' => 'error', 'message' => 'SCHOOL NAME IS TOO LONG.'];
    }

    if (mb_strlen($schoolYear) > 9) {
        return ['status' => 'error', 'message' => 'SCHOOL YEAR IS TOO LONG.'];
    }

    if (!ctype_digit($schoolYear)) {
        return ['status' => 'error', 'message' => 'SCHOOL YEAR MUST CONTAIN NUMBERS ONLY.'];
    }

    $file = $files['pdf_file'] ?? null;
    if (!is_array($file) || ($file['tmp_name'] ?? '') === '') {
        return ['status' => 'error', 'message' => 'PLEASE SELECT A PDF, JPG/JPEG OR PNG FILE TO UPLOAD.'];
    }

    $size = filesize($file['tmp_name']);
    $validation = validate_pdf_file_content($file['tmp_name'], $size === false ? PHP_INT_MAX : $size, $maxBytes);
    if (!$validation['ok']) {
        return ['status' => 'error', 'message' => $validation['error']];
    }

    if ($schoolId > 0) {
        $school = get_school_by_id($pdo, $schoolId);
        if ($school === null) {
            return ['status' => 'error', 'message' => 'SELECTED SCHOOL COULD NOT BE FOUND. PLEASE REFRESH THE PAGE AND TRY AGAIN.'];
        }
    } else {
        $school = find_or_create_school($pdo, $schoolName);
        $school['campus'] = $school['campus'] ?? '';
    }

    $existing = find_existing_upload($pdo, $school['id'], $schoolYear);
    if ($existing !== null && !$confirmDuplicate) {
        return [
            'status' => 'duplicate',
            'message' => 'A LATIN HONOR LIST FOR THIS SCHOOL AND SCHOOL YEAR ALREADY EXISTS.',
            'existing_upload' => $existing,
        ];
    }

    $uploadNumber = next_upload_number($pdo, $school['id'], $schoolYear);
    $storedFilename = build_stored_filename($school['school_name'], $schoolYear, $uploadNumber, (string) ($school['campus'] ?? ''), $validation['extension'] ?? 'pdf');

    $resolvedStorageDir = realpath($storageDir);
    if ($resolvedStorageDir === false) {
        return ['status' => 'error', 'message' => 'THE FILE COULD NOT BE SAVED. PLEASE TRY AGAIN.'];
    }
    $destination = $resolvedStorageDir . DIRECTORY_SEPARATOR . $storedFilename;

    if (file_exists($destination)) {
        return [
            'status' => 'error',
            'message' => 'A FILE WITH THIS NAME ALREADY EXISTS FOR A DIFFERENT SCHOOL. PLEASE CONTACT AN ADMINISTRATOR.',
        ];
    }

    if (!copy($file['tmp_name'], $destination)) {
        return ['status' => 'error', 'message' => 'THE FILE COULD NOT BE SAVED. PLEASE TRY AGAIN.'];
    }

    try {
        $upload = create_upload_record($pdo, [
            'school_id' => $school['id'],
            'school_year' => $schoolYear,
            'original_filename' => (string) ($file['name'] ?? $storedFilename),
            'stored_filename' => $storedFilename,
            'file_path' => $destination,
            'file_size' => filesize($destination),
            'file_hash' => hash_file('sha256', $destination),
            'uploaded_by' => $uploadedBy,
            'upload_number' => $uploadNumber,
        ]);
    } catch (\Throwable $e) {
        error_log('create_upload_record failed after file copy: ' . $e->getMessage());
        @unlink($destination);
        return ['status' => 'error', 'message' => 'THE RECORD COULD NOT BE SAVED. PLEASE TRY AGAIN.'];
    }

    return [
        'status' => 'success',
        'message' => 'LATIN HONOR LIST UPLOADED SUCCESSFULLY.',
        'upload' => $upload,
    ];
}

/**
 * @return array{ok: bool, error?: string}
 */
/**
 * The other campuses an upload can be moved to: the ACTIVE school rows that share
 * the upload's school name (compared case/space-insensitively) but are a different
 * row, i.e. another campus of the same school. A school with a single campus returns [].
 *
 * @return array<int, array{id: int, campus: string}>
 */
function get_other_campuses_for_upload(PDO $pdo, int $uploadId): array
{
    $stmt = $pdo->prepare(
        "SELECT o.id, o.campus
         FROM latin_honor_uploads u
         JOIN schools s ON s.id = u.school_id
         JOIN schools o ON UPPER(TRIM(o.school_name)) = UPPER(TRIM(s.school_name)) AND o.id <> s.id AND o.status = 'active'
         WHERE u.id = :id
         ORDER BY o.campus"
    );
    $stmt->execute(['id' => $uploadId]);

    return array_map(
        static fn (array $row): array => ['id' => (int) $row['id'], 'campus' => (string) $row['campus']],
        $stmt->fetchAll()
    );
}

/**
 * Renames an upload's stored file so its name matches the school, campus, year and
 * number the upload belongs to NOW (build_stored_filename()), and points the row's
 * stored_filename/file_path at it. The original filename the uploader chose is kept.
 * Nothing happens (ok, renamed = false) when the name is already right or the file is
 * not on disk; a different file already holding the new name is refused rather than
 * overwritten. Meant to run inside the caller's transaction: on a later failure call
 * restore_upload_file_rename() with the returned 'undo'.
 *
 * @return array{ok: true, renamed: bool, from?: string, to?: string, undo?: array{from: string, to: string}}|array{ok: false, error: string}
 */
function rename_upload_file_to_match_school(PDO $pdo, int $uploadId): array
{
    $upload = get_upload_by_id($pdo, $uploadId);
    if ($upload === null) {
        return ['ok' => false, 'error' => 'UPLOAD RECORD NOT FOUND.'];
    }

    $numberStmt = $pdo->prepare('SELECT upload_number FROM latin_honor_uploads WHERE id = :id');
    $numberStmt->execute(['id' => $uploadId]);
    $extension = (string) pathinfo((string) $upload['stored_filename'], PATHINFO_EXTENSION);
    $newName = build_stored_filename(
        (string) $upload['school_name'],
        (string) $upload['school_year'],
        (int) $numberStmt->fetchColumn(),
        (string) ($upload['campus'] ?? ''),
        $extension === '' ? 'pdf' : $extension
    );

    $oldPath = (string) $upload['file_path'];
    $oldName = (string) $upload['stored_filename'];
    if ($newName === $oldName || !is_file($oldPath)) {
        return ['ok' => true, 'renamed' => false];
    }

    // keep the stored path's own style (separators) and folder, swapping only the file name
    $newPath = substr($oldPath, 0, strlen($oldPath) - strlen(basename(str_replace('\\', '/', $oldPath)))) . $newName;
    if (file_exists($newPath)) {
        return ['ok' => false, 'error' => 'A FILE NAMED ' . $newName . ' ALREADY EXISTS. PLEASE CONTACT AN ADMINISTRATOR.'];
    }
    if (!@rename($oldPath, $newPath)) {
        return ['ok' => false, 'error' => 'THE FILE COULD NOT BE RENAMED. PLEASE TRY AGAIN.'];
    }

    try {
        $pdo->prepare('UPDATE latin_honor_uploads SET stored_filename = :name, file_path = :path WHERE id = :id')
            ->execute(['name' => $newName, 'path' => $newPath, 'id' => $uploadId]);
    } catch (\Throwable $e) {
        @rename($newPath, $oldPath);
        error_log('rename_upload_file_to_match_school failed: ' . $e->getMessage());

        return ['ok' => false, 'error' => 'THE FILE COULD NOT BE RENAMED. PLEASE TRY AGAIN.'];
    }

    return ['ok' => true, 'renamed' => true, 'from' => $oldName, 'to' => $newName, 'undo' => ['from' => $newPath, 'to' => $oldPath]];
}

/**
 * Moves an upload, together with its saved records, to another campus of the same
 * school (for a list that was filed under the wrong campus). The records' data and
 * every filed application stay as they are; the stored file is renamed to carry the new
 * campus (rename_upload_file_to_match_school()) and keeps its content. The upload keeps its number unless the new campus
 * already has an upload with that number for the same school year (the numbering is
 * per school and year), in which case it takes the next free one. Records of this
 * upload that sit in the recycle bin follow it, so restoring them later lands in the
 * right campus. Everything happens in one transaction.
 *
 * @return array{ok: true, from: string, to: string, records: int, file?: string}|array{ok: false, error: string}
 */
function change_upload_campus(PDO $pdo, int $uploadId, int $newSchoolId): array
{
    $upload = get_upload_by_id($pdo, $uploadId);
    if ($upload === null) {
        return ['ok' => false, 'error' => 'UPLOAD RECORD NOT FOUND.'];
    }

    $target = null;
    foreach (get_other_campuses_for_upload($pdo, $uploadId) as $campus) {
        if ($campus['id'] === $newSchoolId) {
            $target = $campus;
            break;
        }
    }
    if ($target === null) {
        return ['ok' => false, 'error' => 'THAT CAMPUS IS NOT AVAILABLE FOR THIS SCHOOL. CHOOSE ANOTHER ACTIVE CAMPUS OF THE SAME SCHOOL.'];
    }

    $moved = move_upload_to_school($pdo, $upload, $newSchoolId);
    if (!$moved['ok']) {
        return $moved;
    }

    return [
        'ok' => true,
        'from' => format_school_display_name((string) $upload['school_name'], $upload['campus'] ?? null),
        'to' => format_school_display_name((string) $upload['school_name'], $target['campus']),
        'records' => $moved['records'],
        'file' => $moved['file'],
    ];
}

/**
 * The move itself, shared by change_upload_campus() (another campus of the same school) and
 * transfer_upload_to_school() (any other school); the callers decide whether the target is
 * allowed. In ONE transaction it points the upload, its saved records and its recycle-bin
 * records at $newSchoolId, gives the upload the next free number when the target already
 * uses its number for that school year, and renames the stored file to carry the new school
 * and campus (the rename is undone if anything later fails). Applications and the records'
 * data are not touched.
 *
 * @param array<string, mixed> $upload as returned by get_upload_by_id()
 * @return array{ok: true, records: int, file: ?string}|array{ok: false, error: string}
 */
function move_upload_to_school(PDO $pdo, array $upload, int $newSchoolId): array
{
    $uploadId = (int) $upload['id'];
    $fileUndo = null;
    $newFile = null;
    try {
        $pdo->beginTransaction();

        $numberStmt = $pdo->prepare('SELECT upload_number FROM latin_honor_uploads WHERE id = :id');
        $numberStmt->execute(['id' => $uploadId]);
        $number = (int) $numberStmt->fetchColumn();

        $clashStmt = $pdo->prepare('SELECT COUNT(*) FROM latin_honor_uploads WHERE school_id = :school_id AND school_year = :school_year AND upload_number = :number');
        $clashStmt->execute(['school_id' => $newSchoolId, 'school_year' => $upload['school_year'], 'number' => $number]);
        if ((int) $clashStmt->fetchColumn() > 0) {
            $number = next_upload_number($pdo, $newSchoolId, (string) $upload['school_year']);
        }

        $pdo->prepare('UPDATE latin_honor_uploads SET school_id = :school_id, upload_number = :number WHERE id = :id')
            ->execute(['school_id' => $newSchoolId, 'number' => $number, 'id' => $uploadId]);

        $moved = $pdo->prepare('UPDATE latin_honor_graduates SET school_id = :school_id WHERE upload_id = :id');
        $moved->execute(['school_id' => $newSchoolId, 'id' => $uploadId]);
        $records = $moved->rowCount();

        $pdo->prepare('UPDATE graduate_recycle_bin SET school_id = :school_id WHERE upload_id = :id')
            ->execute(['school_id' => $newSchoolId, 'id' => $uploadId]);

        // the stored file's name carries the campus, so it follows the move
        $renamed = rename_upload_file_to_match_school($pdo, $uploadId);
        if (!$renamed['ok']) {
            $pdo->rollBack();

            return ['ok' => false, 'error' => $renamed['error']];
        }
        $fileUndo = $renamed['undo'] ?? null;
        $newFile = $renamed['to'] ?? null;

        $pdo->commit();
    } catch (\Throwable $e) {
        if ($fileUndo !== null) {
            @rename($fileUndo['from'], $fileUndo['to']);
        }
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (\Throwable $rollbackError) {
            error_log('move_upload_to_school rollback failed: ' . $rollbackError->getMessage());
        }
        error_log('move_upload_to_school failed: ' . $e->getMessage());

        return ['ok' => false, 'error' => 'THE UPLOAD COULD NOT BE MOVED. PLEASE TRY AGAIN.'];
    }

    return ['ok' => true, 'records' => $records, 'file' => $newFile];
}

/**
 * The schools an upload can be transferred to: every ACTIVE school row except the one it
 * is filed under, with how many uploads that school already has for the upload's school
 * year (so the screen can warn that the file will become one more list for that year, shown
 * as a possible duplicate) and the number the transferred upload would get.
 *
 * @return array<int, array{id: int, school_name: string, campus: string, display: string, same_year_uploads: int, next_number: int}>
 */
function get_transfer_targets_for_upload(PDO $pdo, int $uploadId): array
{
    $upload = get_upload_by_id($pdo, $uploadId);
    if ($upload === null) {
        return [];
    }

    $stmt = $pdo->prepare(
        "SELECT s.id, s.school_name, s.campus,
                (SELECT COUNT(*) FROM latin_honor_uploads x WHERE x.school_id = s.id AND x.school_year = :year_count) AS same_year_uploads
         FROM schools s
         WHERE s.status = 'active' AND s.id <> :current
         ORDER BY s.school_name, s.campus"
    );
    $stmt->execute(['year_count' => $upload['school_year'], 'current' => $upload['school_id']]);

    $numberStmt = $pdo->prepare('SELECT upload_number FROM latin_honor_uploads WHERE id = :id');
    $numberStmt->execute(['id' => $uploadId]);
    $ownNumber = (int) $numberStmt->fetchColumn();

    $clashStmt = $pdo->prepare('SELECT COUNT(*) FROM latin_honor_uploads WHERE school_id = :school_id AND school_year = :school_year AND upload_number = :number');

    $targets = [];
    foreach ($stmt->fetchAll() as $row) {
        $existing = (int) $row['same_year_uploads'];
        $clashStmt->execute(['school_id' => $row['id'], 'school_year' => $upload['school_year'], 'number' => $ownNumber]);
        $targets[] = [
            'id' => (int) $row['id'],
            'school_name' => (string) $row['school_name'],
            'campus' => (string) $row['campus'],
            'display' => format_school_display_name((string) $row['school_name'], (string) $row['campus']),
            'same_year_uploads' => $existing,
            // the number is kept unless the target uses it for that year; then the next free one
            'next_number' => (int) $clashStmt->fetchColumn() > 0 ? next_upload_number($pdo, (int) $row['id'], (string) $upload['school_year']) : $ownNumber,
        ];
    }

    return $targets;
}

/**
 * Transfers an upload and its saved records to ANY other active school, for a list that was
 * filed under the wrong school (change_upload_campus() only reaches another campus of the same
 * school). The data and every application stay; the school on the records changes with the
 * upload, and the stored file is renamed for the new school (move_upload_to_school()).
 * An upload with an unfinished saved review (status draft) is refused: the draft belongs to
 * the school it was started under.
 *
 * @return array{ok: true, from: string, to: string, records: int, file: ?string, upload_number: int}|array{ok: false, error: string}
 */
function transfer_upload_to_school(PDO $pdo, int $uploadId, int $newSchoolId): array
{
    $upload = get_upload_by_id($pdo, $uploadId);
    if ($upload === null) {
        return ['ok' => false, 'error' => 'UPLOAD RECORD NOT FOUND.'];
    }
    if ($upload['extraction_status'] === 'draft') {
        return ['ok' => false, 'error' => 'THIS UPLOAD HAS A SAVED DRAFT REVIEW. FINISH THE REVIEW (OR SAVE THE RECORDS) BEFORE TRANSFERRING IT TO ANOTHER SCHOOL.'];
    }

    $target = null;
    foreach (get_transfer_targets_for_upload($pdo, $uploadId) as $candidate) {
        if ($candidate['id'] === $newSchoolId) {
            $target = $candidate;
            break;
        }
    }
    if ($target === null) {
        return ['ok' => false, 'error' => 'THAT SCHOOL IS NOT AVAILABLE. CHOOSE ANOTHER ACTIVE SCHOOL.'];
    }

    $moved = move_upload_to_school($pdo, $upload, $newSchoolId);
    if (!$moved['ok']) {
        return $moved;
    }

    return [
        'ok' => true,
        'from' => format_school_display_name((string) $upload['school_name'], $upload['campus'] ?? null),
        'to' => $target['display'],
        'records' => $moved['records'],
        'file' => $moved['file'],
        'upload_number' => $target['next_number'],
    ];
}

function delete_upload(PDO $pdo, int $uploadId, ?int $userId = null): array
{
    $upload = get_upload_by_id($pdo, $uploadId);
    if ($upload === null) {
        return ['ok' => false, 'error' => 'UPLOAD RECORD NOT FOUND.'];
    }

    if ((int) $upload['records_extracted'] > 0) {
        return ['ok' => false, 'error' => 'THIS UPLOAD HAS EXTRACTED RECORDS AND CANNOT BE DELETED.'];
    }

    // The upload row, its saved draft and its stored file go to the recycle
    // bin (the file is moved, not deleted) instead of being destroyed; any
    // graduate rows still attached are binned as graduates. Restore puts all
    // of it back; only "delete permanently" removes it for good.
    $binFile = null;
    try {
        $binFile = recycle_bin_move_file_in((string) ($upload['file_path'] ?? ''));

        $pdo->beginTransaction();

        $rowStmt = $pdo->prepare('SELECT * FROM latin_honor_uploads WHERE id = :id');
        $rowStmt->execute(['id' => $uploadId]);
        $row = $rowStmt->fetch();

        $draftStmt = $pdo->prepare('SELECT * FROM extraction_review_drafts WHERE upload_id = :id');
        $draftStmt->execute(['id' => $uploadId]);
        $draft = $draftStmt->fetch();
        if ($draft !== false) {
            unset($draft['id']);
        }

        $gradStmt = $pdo->prepare('SELECT id FROM latin_honor_graduates WHERE upload_id = :id');
        $gradStmt->execute(['id' => $uploadId]);
        foreach ($gradStmt->fetchAll(PDO::FETCH_COLUMN) as $graduateId) {
            archive_graduate($pdo, (int) $graduateId, $userId, 'UPLOAD DELETED', 'manual');
        }

        archive_item(
            $pdo,
            'upload',
            $uploadId,
            (string) $upload['original_filename'],
            format_school_display_name((string) $upload['school_name'], $upload['campus'] ?? null) . ' · SY ' . $upload['school_year'] . ' · UPLOAD #' . $row['upload_number'],
            ['upload' => $row, 'draft' => $draft === false ? null : $draft],
            $userId,
            'UPLOAD DELETED',
            $binFile
        );

        $pdo->prepare('DELETE FROM latin_honor_graduates WHERE upload_id = :id')->execute(['id' => $uploadId]);
        $pdo->prepare('DELETE FROM extraction_review_drafts WHERE upload_id = :id')->execute(['id' => $uploadId]);
        $pdo->prepare('DELETE FROM latin_honor_uploads WHERE id = :id')->execute(['id' => $uploadId]);
        $pdo->commit();
    } catch (\Throwable $e) {
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (\Throwable $rollbackError) {
            error_log('delete_upload rollback failed: ' . $rollbackError->getMessage());
        }
        if ($binFile !== null) {
            recycle_bin_move_file_back($binFile, (string) $upload['file_path']);
        }
        error_log('delete_upload failed: ' . $e->getMessage());

        return ['ok' => false, 'error' => recycle_bin_missing_table_message($e) ?? 'THE UPLOAD COULD NOT BE DELETED. PLEASE TRY AGAIN.'];
    }

    return ['ok' => true, 'filename' => (string) $upload['original_filename'], 'school' => (string) $upload['school_name'], 'school_year' => (string) $upload['school_year']];
}

function update_upload_extraction_status(PDO $pdo, int $uploadId, string $status, int $recordsExtracted): void
{
    // updated_at is set explicitly rather than left to the column's
    // ON UPDATE CURRENT_TIMESTAMP: MySQL only fires that when some column's
    // value actually changes, so re-saving a draft that is already
    // status='draft'/records=0 (exactly what save-as-draft always writes)
    // would otherwise leave "last checked" frozen at whenever the status
    // FIRST became 'draft', no matter how many times it's saved again.
    $stmt = $pdo->prepare(
        'UPDATE latin_honor_uploads SET extraction_status = :status, records_extracted = :records, updated_at = :updated_at WHERE id = :id'
    );
    $stmt->execute([
        'status' => $status,
        'records' => $recordsExtracted,
        'updated_at' => date('Y-m-d H:i:s'),
        'id' => $uploadId,
    ]);
}

/**
 * Called after a chunk of AI extraction succeeds — records the document's
 * total page count (set once, on the first chunk of a fresh attempt) and
 * how far extraction has progressed so far.
 */
function update_upload_chunk_progress(PDO $pdo, int $uploadId, int $totalPages, int $extractedThroughPage): void
{
    $stmt = $pdo->prepare(
        'UPDATE latin_honor_uploads SET total_pages = :total_pages, extracted_through_page = :extracted_through_page WHERE id = :id'
    );
    $stmt->execute([
        'total_pages' => $totalPages,
        'extracted_through_page' => $extractedThroughPage,
        'id' => $uploadId,
    ]);
}

/**
 * Called at the start of a fresh AI extraction attempt (first-time, or
 * RE-EXTRACT RECORDS) — clears any chunk progress from a prior attempt, so
 * the next chunk computed is page 1 of a freshly-counted document rather
 * than continuing a stale cursor. Does not touch extraction_review_drafts;
 * callers that also need the existing draft cleared (RE-EXTRACT RECORDS)
 * call delete_extraction_draft() separately, since includes/*.php files in
 * this codebase don't cross-require each other.
 */
function reset_upload_chunk_progress(PDO $pdo, int $uploadId): void
{
    $pdo->prepare('UPDATE latin_honor_uploads SET total_pages = NULL, extracted_through_page = 0 WHERE id = :id')
        ->execute(['id' => $uploadId]);
}

/**
 * True when a chunked AI extraction is in progress but not yet covering
 * every page. admin/view_upload.php uses this to show "Continue
 * Extraction" instead of "Resume Draft Review"; admin/review.php uses it to
 * refuse opening a review until extraction finishes. total_pages is NULL
 * for every upload that isn't mid-chunked-extraction — a pre-existing
 * upload, or one that finished in a single chunk — so this is always false
 * for those, regardless of extracted_through_page's value.
 */
function upload_needs_more_extraction(array $upload): bool
{
    return $upload['total_pages'] !== null && (int) $upload['extracted_through_page'] < (int) $upload['total_pages'];
}
