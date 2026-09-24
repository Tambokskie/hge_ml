<?php

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/schools.php';
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/pdf_files.php';
require_once __DIR__ . '/extraction_review.php';
require_once __DIR__ . '/xlsx_reader.php';

/**
 * The data table's column header row (see build_latin_honor_template_xlsx()).
 * Column order is significant: it is how a row's cells are mapped to fields.
 */
const TEMPLATE_COLUMN_HEADERS = ['SEQUENCE', 'LAST NAME', 'GIVEN NAME', 'MIDDLE NAME', 'LATIN HONOR', 'COURSE'];

function xlsx_cell_value(array $sheetRows, int $row, int $col): string
{
    return trim((string) ($sheetRows[$row][$col] ?? ''));
}

function normalize_template_label(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/^\[|\]$/', '', $value) ?? $value;

    return mb_strtoupper(trim($value));
}

/**
 * The template's canonical date format is DAY/MONTH/YEAR (e.g. "24/07/2025"
 * — see build_latin_honor_template_xlsx()'s "[DAY/MONTH/YEAR]" placeholder),
 * checked first and matched exactly so it's never ambiguous with a US-style
 * MM/DD/YYYY reading. Falls back to ISO "2025-07-24", human dates like
 * "July 24, 2025" (strtotime), and a raw Excel serial day-count — the form a
 * date cell actually lands in if Excel auto-formatted a typed-in date as a
 * real date, since our reader intentionally returns numeric cells as their
 * raw <v> string rather than guessing their format.
 */
function parse_template_date_value(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '' || normalize_template_label($raw) === 'DAY/MONTH/YEAR') {
        return null;
    }

    $dt = DateTime::createFromFormat('d/m/Y', $raw);
    if ($dt && $dt->format('d/m/Y') === $raw) {
        return $dt->format('Y-m-d');
    }

    // A slash-delimited value that didn't match DAY/MONTH/YEAR exactly is a
    // malformed date, not a hint to try a different (ambiguous) order —
    // never fall through to strtotime()'s US-centric MONTH/DAY/YEAR reading
    // for these, or "07/13/2025" would silently become July 13 instead of
    // being rejected as the invalid 13th month it is under this format.
    if (strpos($raw, '/') !== false) {
        return null;
    }

    $dt = DateTime::createFromFormat('Y-m-d', $raw);
    if ($dt && $dt->format('Y-m-d') === $raw) {
        return $raw;
    }

    if (is_numeric($raw) && (float) $raw > 0) {
        $unixSeconds = ((float) $raw - 25569) * 86400;
        if ($unixSeconds > 0) {
            return gmdate('Y-m-d', (int) round($unixSeconds));
        }
    }

    $timestamp = strtotime($raw);

    return $timestamp !== false ? date('Y-m-d', $timestamp) : null;
}

function normalize_template_honor_value(string $raw): ?string
{
    $normalized = mb_strtoupper(trim(preg_replace('/\s+/', ' ', $raw) ?? $raw));

    return in_array($normalized, ['CUM LAUDE', 'MAGNA CUM LAUDE', 'SUMMA CUM LAUDE'], true) ? $normalized : null;
}

function xlsx_row_is_blank(array $sheetRows, int $row, int $fromCol, int $toCol): bool
{
    for ($col = $fromCol; $col <= $toCol; $col++) {
        if (xlsx_cell_value($sheetRows, $row, $col) !== '') {
            return false;
        }
    }

    return true;
}

/**
 * Pure, DB-independent parse of an already-read worksheet ([rowNum =>
 * [colIndex => value]], as returned by xlsx_read_first_sheet()) into the
 * header fields and graduate rows the template describes. Deliberately does
 * not touch the database — find_school_by_exact_name_campus() and the
 * duplicate-upload check happen in the caller, so this function (and the
 * template-format/required-field validation it does) can be unit-tested
 * without a database connection.
 *
 * @return array{status: 'error', message: string}|array{status: 'success', school_name: string, campus: string, school_year: string, graduation_date: ?string, rows: array}
 */
function parse_latin_honor_template(array $sheetRows): array
{
    $schoolName = xlsx_cell_value($sheetRows, 1, 1);
    $campus = xlsx_cell_value($sheetRows, 2, 1);
    $dateRaw = xlsx_cell_value($sheetRows, 3, 1);
    $yearRaw = xlsx_cell_value($sheetRows, 4, 1);

    // Leftover placeholder text (an old-style template, or a school row
    // that was never filled in) reads the same as blank.
    if (normalize_template_label($schoolName) === 'SCHOOL NAME') {
        $schoolName = '';
    }
    if (normalize_template_label($campus) === 'CAMPUS') {
        $campus = '';
    }

    if ($schoolName === '') {
        return ['status' => 'error', 'message' => 'THE TEMPLATE IS MISSING THE SCHOOL NAME IN CELL A1. DOWNLOAD THE TEMPLATE FOR THE CORRECT SCHOOL FIRST.'];
    }

    $graduationDate = parse_template_date_value($dateRaw);
    if ($dateRaw !== '' && normalize_template_label($dateRaw) !== 'DAY/MONTH/YEAR' && $graduationDate === null) {
        return ['status' => 'error', 'message' => 'DATE OF GRADUATION IN THE TEMPLATE IS NOT A VALID DATE. USE THE FORMAT DAY/MONTH/YEAR (e.g. 24/07/2025).'];
    }

    $year = null;
    if (preg_match('/\d{4}/', $yearRaw, $m)) {
        $year = (int) $m[0];
    } elseif ($graduationDate !== null) {
        $year = (int) date('Y', strtotime($graduationDate));
    }

    if ($year === null || $year < 1901 || $year > 2155) {
        return ['status' => 'error', 'message' => 'YEAR IN THE TEMPLATE IS MISSING OR INVALID. ENTER A VALID 4-DIGIT YEAR.'];
    }

    $headerRow = null;
    foreach ($sheetRows as $rowNum => $cols) {
        $matches = true;
        foreach (TEMPLATE_COLUMN_HEADERS as $colIndex => $expected) {
            if (mb_strtoupper(xlsx_cell_value($sheetRows, $rowNum, $colIndex + 1)) !== $expected) {
                $matches = false;
                break;
            }
        }
        if ($matches) {
            $headerRow = $rowNum;
            break;
        }
    }

    if ($headerRow === null) {
        return [
            'status' => 'error',
            'message' => 'THIS DOES NOT LOOK LIKE THE LATIN HONOR TEMPLATE. THE SEQUENCE / LAST NAME / GIVEN NAME / MIDDLE NAME / LATIN HONOR / COURSE HEADER ROW COULD NOT BE FOUND.',
        ];
    }

    $lastDataRow = max(array_keys($sheetRows));

    $rows = [];
    $invalidExcelRows = [];
    for ($rowNum = $headerRow + 1; $rowNum <= $lastDataRow; $rowNum++) {
        if (!isset($sheetRows[$rowNum]) || xlsx_row_is_blank($sheetRows, $rowNum, 2, 6)) {
            continue;
        }

        $lastName = xlsx_cell_value($sheetRows, $rowNum, 2);
        $givenName = xlsx_cell_value($sheetRows, $rowNum, 3);
        $middleName = xlsx_cell_value($sheetRows, $rowNum, 4);
        $honorRaw = xlsx_cell_value($sheetRows, $rowNum, 5);
        $course = xlsx_cell_value($sheetRows, $rowNum, 6);

        $honor = $honorRaw !== '' ? normalize_template_honor_value($honorRaw) : null;

        if ($lastName === '' || $givenName === '' || $course === '' || $honor === null) {
            $invalidExcelRows[] = $rowNum;
            continue;
        }

        // "LAST NAME, GIVEN NAME MIDDLE NAME" — comma after the surname,
        // matching the template's Last Name / Given Name / Middle Name
        // column order.
        $firstAndMiddle = trim(preg_replace('/\s+/', ' ', "{$givenName} {$middleName}") ?? '');
        $graduateName = mb_strtoupper(trim("{$lastName}, {$firstAndMiddle}"));

        $rows[] = [
            'graduate_name' => $graduateName,
            'course' => mb_strtoupper(trim(preg_replace('/\s+/', ' ', $course) ?? $course)),
            'latin_honor' => $honor,
            'graduation_date' => $graduationDate,
            'graduation_year' => $year,
            'source' => 'template',
        ];
    }

    if (!empty($invalidExcelRows)) {
        return [
            'status' => 'error',
            'message' => 'EXCEL ROW(S) ' . implode(', ', $invalidExcelRows)
                . ' ARE MISSING A REQUIRED FIELD (LAST NAME, GIVEN NAME, COURSE) OR HAVE AN INVALID LATIN HONOR VALUE. PLEASE FIX AND RE-UPLOAD.',
        ];
    }

    if (empty($rows)) {
        return ['status' => 'error', 'message' => 'THE TEMPLATE HAS NO GRADUATE RECORDS TO IMPORT.'];
    }

    return [
        'status' => 'success',
        'school_name' => $schoolName,
        'campus' => $campus,
        'school_year' => (string) $year,
        'graduation_date' => $graduationDate,
        'rows' => $rows,
    ];
}

/**
 * @return array{ok: bool, error?: string}
 */
function validate_xlsx_file_content(string $path, int $size, int $maxBytes): array
{
    if ($size > $maxBytes) {
        return ['ok' => false, 'error' => 'THE FILE EXCEEDS THE MAXIMUM ALLOWED SIZE.'];
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $path);
    finfo_close($finfo);

    $isOfficeXlsx = $mime === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    // Some environments' fileinfo databases only recognize the underlying
    // zip container, not the OOXML spreadsheet type specifically — fall back
    // to checking the zip file signature in that case.
    $isZipContainer = $mime === 'application/zip' && (@file_get_contents($path, false, null, 0, 2) === 'PK');

    if (!$isOfficeXlsx && !$isZipContainer) {
        return ['ok' => false, 'error' => 'ONLY EXCEL (.XLSX) FILES ARE ACCEPTED.'];
    }

    return ['ok' => true];
}

/**
 * Combined validate + resolve-school-and-duplicates + stage-for-review
 * handler for the "UPLOAD FROM TEMPLATE" flow — mirrors handle_upload_request()
 * in uploads.php, but since the Excel file is already structured data (no OCR
 * step needed), this does in one request what the PDF flow spreads across
 * upload_pdf.php + extract_pdf.php: validate the file, confirm the school
 * already exists (never auto-create it, unlike the PDF flow), and land the
 * parsed rows directly in the extraction review session.
 */
function handle_template_upload_request(PDO $pdo, array $post, array $files, int $uploadedBy, string $storageDir, int $maxBytes = 20971520): array
{
    $file = $files['excel_file'] ?? null;
    if (!is_array($file) || ($file['tmp_name'] ?? '') === '') {
        return ['status' => 'error', 'message' => 'PLEASE SELECT AN EXCEL (.XLSX) FILE TO UPLOAD.'];
    }

    $size = filesize($file['tmp_name']);
    $validation = validate_xlsx_file_content($file['tmp_name'], $size === false ? PHP_INT_MAX : $size, $maxBytes);
    if (!$validation['ok']) {
        return ['status' => 'error', 'message' => $validation['error']];
    }

    $sheet = xlsx_read_first_sheet($file['tmp_name']);
    if (!$sheet['ok']) {
        return ['status' => 'error', 'message' => $sheet['error']];
    }

    $parsed = parse_latin_honor_template($sheet['rows']);
    if ($parsed['status'] !== 'success') {
        return $parsed;
    }

    $school = find_school_by_exact_name_campus($pdo, $parsed['school_name'], $parsed['campus']);
    if ($school === null) {
        $displayName = format_school_display_name($parsed['school_name'], $parsed['campus']);

        return [
            'status' => 'school_not_found',
            'message' => mb_strtoupper($displayName) . ' IS NOT YET IN SCHOOL MANAGEMENT. PLEASE ADD IT THERE FIRST, THEN UPLOAD THIS TEMPLATE AGAIN.',
        ];
    }

    $confirmDuplicate = !empty($post['confirm_duplicate']);
    $existing = find_existing_upload($pdo, $school['id'], $parsed['school_year']);
    if ($existing !== null && !$confirmDuplicate) {
        return [
            'status' => 'duplicate',
            'message' => 'A LATIN HONOR LIST FOR THIS SCHOOL AND SCHOOL YEAR ALREADY EXISTS.',
            'existing_upload' => $existing,
        ];
    }

    $uploadNumber = next_upload_number($pdo, $school['id'], $parsed['school_year']);
    $storedFilename = build_stored_filename($school['school_name'], $parsed['school_year'], $uploadNumber, (string) ($school['campus'] ?? ''), 'xlsx');

    $resolvedStorageDir = realpath($storageDir);
    if ($resolvedStorageDir === false) {
        return ['status' => 'error', 'message' => 'THE FILE COULD NOT BE SAVED. PLEASE TRY AGAIN.'];
    }
    $destination = $resolvedStorageDir . DIRECTORY_SEPARATOR . $storedFilename;

    if (file_exists($destination)) {
        return ['status' => 'error', 'message' => 'A FILE WITH THIS NAME ALREADY EXISTS FOR A DIFFERENT SCHOOL. PLEASE CONTACT AN ADMINISTRATOR.'];
    }

    if (!copy($file['tmp_name'], $destination)) {
        return ['status' => 'error', 'message' => 'THE FILE COULD NOT BE SAVED. PLEASE TRY AGAIN.'];
    }

    try {
        $upload = create_upload_record($pdo, [
            'school_id' => $school['id'],
            'school_year' => $parsed['school_year'],
            'original_filename' => (string) ($file['name'] ?? $storedFilename),
            'stored_filename' => $storedFilename,
            'file_path' => $destination,
            'file_size' => filesize($destination),
            'file_hash' => hash_file('sha256', $destination),
            'uploaded_by' => $uploadedBy,
            'upload_number' => $uploadNumber,
        ]);
    } catch (\Throwable $e) {
        error_log('create_upload_record failed after template file copy: ' . $e->getMessage());
        @unlink($destination);
        return ['status' => 'error', 'message' => 'THE RECORD COULD NOT BE SAVED. PLEASE TRY AGAIN.'];
    }

    start_extraction_review($upload['id'], $parsed['rows']);
    update_upload_extraction_status($pdo, $upload['id'], 'extracted', 0);

    return [
        'status' => 'success',
        'message' => count($parsed['rows']) . ' RECORD(S) LOADED FROM THE TEMPLATE.',
        'upload' => $upload,
        'redirect_url' => APP_BASE_URL . '/admin/review.php?upload_id=' . $upload['id'],
    ];
}
