<?php

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function e_upper(?string $value): string
{
    return e(mb_strtoupper((string) $value));
}

function asset_url(string $path): string
{
    $relative = ltrim($path, '/');
    $fullPath = __DIR__ . '/../' . $relative;
    $version = is_file($fullPath) ? filemtime($fullPath) : time();

    return APP_BASE_URL . '/' . $relative . '?v=' . $version;
}

/**
 * The school display name used everywhere in the UI: "SCHOOL NAME-CAMPUS"
 * when the school has a campus on file, otherwise just the school name.
 */
function format_school_display_name(string $schoolName, ?string $campus = null): string
{
    $campus = trim((string) $campus);

    return $campus === '' ? $schoolName : $schoolName . '-' . $campus;
}

/**
 * The year a graduate finished, as it must be stored in graduation_year: the year of the graduation date
 * when there is a valid Y-m-d date, otherwise the year as given. The date is the more specific value, so a
 * year typed beside it can never disagree with it (Look-Up's year filter and the dashboard count by
 * graduation_year while the tables show graduation_date).
 *
 * @param mixed $year the year as submitted or stored (used only when there is no valid date)
 */
function resolve_graduation_year(?string $graduationDate, $year): int
{
    if ($graduationDate !== null && $graduationDate !== '') {
        $dt = DateTime::createFromFormat('Y-m-d', $graduationDate);
        if ($dt && $dt->format('Y-m-d') === $graduationDate) {
            return (int) $dt->format('Y');
        }
    }

    return (int) $year;
}

/**
 * Tailwind classes for a Latin Honor pill badge, color-coded by tier
 * (mirrored in assets/js/lookup.js's honorPillClass() for the AJAX-rendered table).
 */
function latin_honor_pill_class(string $honor): string
{
    switch (strtoupper(trim($honor))) {
        case 'SUMMA CUM LAUDE':
            return 'bg-amber-500/15 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400';
        case 'MAGNA CUM LAUDE':
            return 'bg-csc-navy-600/10 text-csc-navy-600 dark:bg-csc-navy-600/15 dark:text-csc-navy-300';
        default:
            return 'bg-green-500/15 text-green-700 dark:bg-green-500/10 dark:text-green-400';
    }
}

/**
 * Display label for a saved graduate row's extraction source (how the
 * record was read off the original document — AI, OCR, the PDF's text
 * layer, or typed in by hand). Records saved before this was tracked carry
 * no source at all; '' tells the caller to render nothing rather than an
 * "UNKNOWN" badge for thousands of pre-existing rows.
 */
function extraction_source_label(?string $source): string
{
    switch ($source) {
        case 'ai':
            return 'AI';
        case 'ocr':
            return 'OCR';
        case 'text':
            return 'TEXT';
        case 'manual':
            return 'MANUAL';
        default:
            return '';
    }
}

/**
 * Visual urgency tier for a still-pending (0 extracted records) upload,
 * based on how many whole days it's been sitting unprocessed: nothing for
 * under 3 days, a light-orange highlight for 3-6 days, and a pulsing
 * light-red highlight past 6. Returned as literal Tailwind utility
 * classes (not a custom @apply'd component class) — the animate-pending-*
 * utilities are otherwise never referenced from a Tailwind-scanned file
 * (see tailwind.config.js's content globs), and Tailwind only emits a
 * custom animation's @keyframes for utilities it can actually discover.
 */
function pending_upload_age_class(array $upload): string
{
    if ((int) ($upload['records_extracted'] ?? 0) !== 0) {
        return '';
    }

    $uploadedAt = strtotime((string) ($upload['uploaded_at'] ?? ''));
    if ($uploadedAt === false) {
        return '';
    }

    $ageDays = (int) floor((time() - $uploadedAt) / 86400);

    if ($ageDays > 6) {
        return 'border-l-4 border-red-400 bg-red-100 dark:border-red-400/60 dark:bg-red-500/10 motion-safe:animate-pending-critical-pulse dark:motion-safe:animate-pending-critical-pulse-dark';
    }

    if ($ageDays >= 3) {
        return 'border-l-4 border-orange-400 bg-orange-50 dark:border-orange-400/60 dark:bg-orange-500/10';
    }

    return '';
}

/**
 * Whole days elapsed since a datetime, for the SCHOOL RECORDS page's
 * "X DAYS SINCE UPLOAD" line on pending (not-yet-reviewed) uploads.
 */
function days_since(string $datetime): int
{
    $timestamp = strtotime($datetime);
    if ($timestamp === false) {
        return 0;
    }

    return (int) floor((time() - $timestamp) / 86400);
}

function format_date_display(?string $date): string
{
    if (empty($date)) {
        return '—';
    }

    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt) {
        return '—';
    }

    return mb_strtoupper($dt->format('F j, Y'));
}

function paginate(int $totalRows, int $page, int $perPage = 25): array
{
    $perPage = max(1, $perPage);
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    $page = max(1, min($page, $totalPages));
    $offset = ($page - 1) * $perPage;

    return [
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => $totalPages,
        'total_rows' => $totalRows,
        'offset' => $offset,
    ];
}

/**
 * Up to 5 page numbers to render as pagination pills, centered on the
 * current page and clamped to the valid [1, totalPages] range (mirrored
 * in assets/js/lookup.js's paginationWindow() for the AJAX-rendered table).
 */
function pagination_window(int $page, int $totalPages, int $windowSize = 5): array
{
    if ($totalPages <= $windowSize) {
        return range(1, $totalPages);
    }

    $half = (int) floor($windowSize / 2);
    $start = max(1, min($page - $half, $totalPages - $windowSize + 1));

    return range($start, $start + $windowSize - 1);
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function ensure_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
        session_start();
    }
}

function flash_set(string $type, string $message): void
{
    ensure_session();
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array
{
    ensure_session();
    if (empty($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function get_dashboard_stats(PDO $pdo): array
{
    $totalGraduates = (int) $pdo->query('SELECT COUNT(*) FROM latin_honor_graduates')->fetchColumn();
    $totalSchools = (int) $pdo->query('SELECT COUNT(DISTINCT school_id) FROM latin_honor_uploads')->fetchColumn();
    $totalActiveSchools = (int) $pdo->query("SELECT COUNT(*) FROM schools WHERE status = 'active'")->fetchColumn();
    $totalUploads = (int) $pdo->query('SELECT COUNT(*) FROM latin_honor_uploads')->fetchColumn();
    $currentYear = $pdo->query('SELECT MAX(graduation_year) FROM latin_honor_graduates')->fetchColumn();

    $currentYearRecords = $currentYear !== null ? get_graduate_count_by_year($pdo, (int) $currentYear) : 0;

    return [
        'total_graduates' => $totalGraduates,
        'total_schools' => $totalSchools,
        'total_active_schools' => $totalActiveSchools,
        'total_uploads' => $totalUploads,
        'applied_year' => (int) date('Y'),
        'applied_this_year' => get_applied_count_by_year($pdo, (int) date('Y')),
        'current_year' => $currentYear !== null ? (int) $currentYear : null,
        'current_year_records' => $currentYearRecords,
    ];
}

/**
 * Backs the dashboard's "CURRENT YEAR RECORDS" card year dropdown
 * (admin/dashboard.php + api/get_dashboard_year_records.php) — lets an
 * admin pick any year with records, not just the most recent one
 * get_dashboard_stats() defaults to.
 */
function get_graduate_count_by_year(PDO $pdo, int $year): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM latin_honor_graduates WHERE graduation_year = :year');
    $stmt->execute(['year' => $year]);

    return (int) $stmt->fetchColumn();
}

function get_honor_type_breakdown(PDO $pdo): array
{
    $counts = [
        'CUM LAUDE' => 0,
        'MAGNA CUM LAUDE' => 0,
        'SUMMA CUM LAUDE' => 0,
    ];

    $stmt = $pdo->query('SELECT latin_honor, COUNT(*) AS total FROM latin_honor_graduates GROUP BY latin_honor');
    foreach ($stmt->fetchAll() as $row) {
        $counts[$row['latin_honor']] = (int) $row['total'];
    }

    return $counts;
}

function get_upload_activity_by_month(PDO $pdo, int $months = 6): array
{
    $months = max(1, $months);
    $start = date('Y-m-01', strtotime('-' . ($months - 1) . ' months'));

    $stmt = $pdo->prepare(
        "SELECT DATE_FORMAT(uploaded_at, '%Y-%m') AS ym, COUNT(*) AS total
         FROM latin_honor_uploads
         WHERE uploaded_at >= :start
         GROUP BY ym"
    );
    $stmt->execute(['start' => $start]);
    $byMonth = array_column($stmt->fetchAll(), 'total', 'ym');

    $series = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $ym = date('Y-m', strtotime('-' . $i . ' months'));
        $series[] = [
            'label' => strtoupper(date('M', strtotime($ym . '-01'))),
            'count' => (int) ($byMonth[$ym] ?? 0),
        ];
    }

    return $series;
}

function create_or_update_admin(PDO $pdo, string $username, string $email, string $password): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO users (name, username, email, password, role, status)
         VALUES (:name, :username, :email, :password, :role, :status)
         ON DUPLICATE KEY UPDATE password = VALUES(password), role = VALUES(role), status = VALUES(status)'
    );

    $stmt->execute([
        'name' => 'System Administrator',
        'username' => $username,
        'email' => $email,
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'role' => 'administrator',
        'status' => 'active',
    ]);
}

function sidebar_nav_class(string $currentPage, string $navPage): string
{
    // The sidebar itself is CSC navy in light mode and dark surface in dark mode (see
    // includes/sidebar.php), so the active/inactive treatments below are deliberately
    // NOT mirror images of each other: light mode needs light-on-navy contrast, while
    // dark mode keeps its original navy-accent-on-dark-surface look.
    if ($currentPage === $navPage) {
        return 'bg-white text-csc-navy-600 dark:bg-csc-navy-600/10 dark:text-csc-navy-300 border-l-2 border-white dark:border-csc-navy-600 transition-all duration-200 ease-out active:scale-[0.98]';
    }

    return 'text-white/70 dark:text-gray-400 hover:bg-white/10 dark:hover:bg-white/5 hover:text-white dark:hover:text-white hover:translate-x-0.5 border-l-2 border-transparent transition-all duration-200 ease-out active:scale-[0.98]';
}

/**
 * Tailwind classes + label for an upload's extraction_status pill, shared
 * between admin/view_upload.php and admin/school_records.php (mirrored in
 * assets/js/school_records.js's uploadStatusPill() for the AJAX-rendered rows).
 */
function upload_status_pill(string $status): array
{
    return [
        'pending' => ['label' => 'PENDING EXTRACTION', 'class' => 'bg-gray-500/15 text-gray-700 dark:bg-gray-500/10 dark:text-gray-400'],
        'extracted' => ['label' => 'EXTRACTED', 'class' => 'bg-csc-navy-600/10 text-csc-navy-600 dark:bg-csc-navy-600/15 dark:text-csc-navy-300'],
        'draft' => ['label' => 'DRAFT', 'class' => 'bg-yellow-500/15 text-yellow-700 dark:bg-yellow-500/10 dark:text-yellow-400'],
        'reviewed' => ['label' => 'REVIEWED', 'class' => 'bg-green-500/15 text-green-700 dark:bg-green-500/10 dark:text-green-400'],
        'failed' => ['label' => 'EXTRACTION FAILED', 'class' => 'bg-yellow-500/15 text-yellow-700 dark:bg-yellow-500/10 dark:text-yellow-400'],
    ][$status] ?? ['label' => mb_strtoupper($status), 'class' => 'bg-gray-500/15 text-gray-700 dark:bg-gray-500/10 dark:text-gray-400'];
}

/**
 * Three-state (GREEN/YELLOW/RED) AI extraction availability indicator on
 * admin/upload.php. One deterministic formula, no other signal involved:
 * the percentage of today's assumed total request capacity across all
 * configured Gemini keys that is still AVAILABLE — i.e. 100 minus
 * $usedPercent (GeminiExtractor::getUsageStats()'s 'percent', the fraction
 * of count/key-count*20 used so far today).
 *
 *   available  100%-60%  -> GREEN  ("AVAILABLE")
 *   available  below 60%-40% -> YELLOW ("MODERATE USAGE")
 *   available  below 40%     -> RED    ("LOW AVAILABILITY")
 *
 * A failed extraction isn't a separate trigger — it naturally lowers the
 * available percentage through the same underlying count (and a real quota
 * failure pins used% at 100%/available% at 0% for the rest of the day via
 * GeminiExtractor::isQuotaExhausted()'s existing exhausted_at flag), so a
 * failure that actually reduced capacity shows up here automatically,
 * without a second rule to keep in sync with this one.
 */
function ai_availability_tier(int $usedPercent): array
{
    $availablePercent = max(0, 100 - $usedPercent);

    if ($availablePercent >= 60) {
        $tier = ['label' => 'AVAILABLE', 'bar_class' => 'bg-green-500', 'text_class' => 'text-green-600 dark:text-green-400'];
    } elseif ($availablePercent >= 40) {
        $tier = ['label' => 'MODERATE USAGE', 'bar_class' => 'bg-yellow-400', 'text_class' => 'text-yellow-600 dark:text-yellow-400'];
    } else {
        $tier = ['label' => 'LOW AVAILABILITY', 'bar_class' => 'bg-csc-red-600', 'text_class' => 'text-csc-red-600 dark:text-csc-red-400'];
    }

    return $tier + ['available_percent' => $availablePercent];
}

/**
 * Backs the dashboard's "HGE APPLICATION AS OF" card (admin/dashboard.php +
 * api/get_dashboard_year_records.php?metric=applied): how many applications were
 * filed (application_date) in the given calendar year.
 */
function get_applied_count_by_year(PDO $pdo, int $year): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM graduate_applications WHERE YEAR(application_date) = :year');
    $stmt->execute(['year' => $year]);

    return (int) $stmt->fetchColumn();
}

/**
 * Years offered by that card's dropdown: every year an application was filed in,
 * plus the current year (the default), newest first.
 *
 * @return int[]
 */
function get_application_years(PDO $pdo): array
{
    $years = array_map('intval', $pdo->query('SELECT DISTINCT YEAR(application_date) FROM graduate_applications')->fetchAll(PDO::FETCH_COLUMN));
    $years[] = (int) date('Y');
    $years = array_values(array_unique($years));
    rsort($years);

    return $years;
}

function get_distinct_graduation_years(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT DISTINCT graduation_year FROM latin_honor_graduates ORDER BY graduation_year DESC');
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * A destructive-action reason must be actual free text, not a placeholder
 * like "N/A" or "-" — enforced by requiring letters/digits/spaces only
 * (so a punctuation-only answer like "-" or "N/A" already fails on the
 * "/" or "-") plus at least one letter, so a reason of only digits isn't
 * accepted either. Mirrored in assets/js/lookup.js's isValidRemovalReason()
 * for instant feedback before the request round-trip.
 */
function validate_removal_reason(string $reason): array
{
    $reason = trim($reason);

    if ($reason === '') {
        return ['ok' => false, 'error' => 'PLEASE PROVIDE A REASON FOR REMOVAL.'];
    }

    if (!preg_match('/^[A-Za-z0-9 ]+$/', $reason) || !preg_match('/[A-Za-z]/', $reason)) {
        return ['ok' => false, 'error' => 'REASON FOR REMOVAL MUST BE PLAIN TEXT (LETTERS/NUMBERS ONLY) — NOT "N/A", "-", OR OTHER SPECIAL CHARACTERS.'];
    }

    return ['ok' => true];
}

/**
 * Comparison key for the possible-duplicate-graduate check (see
 * find_saved_duplicates_by_name() in includes/graduates.php) — not a
 * display value. Two independent extractions of the same underlying name
 * (one PDF read by AI, another re-uploaded and OCR'd) can differ in case,
 * whitespace, or punctuation ("DELA CRUZ, JUAN" vs "Dela Cruz Juan") without
 * being a different person, so this strips everything but letters/digits and
 * collapses whitespace before comparing, rather than requiring an exact
 * byte-for-byte match on graduate_name.
 */
function normalize_name_for_matching(string $name): string
{
    $upper = mb_strtoupper(trim($name));
    $stripped = preg_replace('/[^A-Z0-9 ]/', ' ', $upper);

    return trim(preg_replace('/\s+/', ' ', $stripped));
}

function log_activity(PDO $pdo, ?int $userId, string $action, ?string $details = null): void
{
    // created_at is stamped from PHP's own clock (pinned to Asia/Manila in
    // config/database.php) rather than left to the database's CURRENT_TIMESTAMP
    // default, since that default follows whatever time zone the MySQL server
    // itself happens to be configured with — which can silently drift from local
    // time depending on the host.
    $stmt = $pdo->prepare('INSERT INTO activity_log (user_id, action, details, created_at) VALUES (:user_id, :action, :details, :created_at)');
    $stmt->execute([
        'user_id' => $userId,
        'action' => $action,
        'details' => $details,
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    $pdo->exec('DELETE FROM activity_log WHERE created_at < (NOW() - INTERVAL 6 MONTH)');
}

/**
 * Pure. Reads Excel-style column filters from their JSON form (?cf={"school":["X","Y"],"date":[null,"2025-07-05"]}):
 * only the known columns, each a list of strings (null = BLANKS), capped so a hand-built URL cannot ask for
 * thousands of bound values. Anything malformed gives an empty result (no column filters).
 *
 * @param list<string> $knownColumns
 * @return array<string, list<?string>>
 */
function parse_column_filters(?string $json, array $knownColumns): array
{
    if ($json === null || trim($json) === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }

    $result = [];
    foreach ($decoded as $column => $values) {
        if (!is_string($column) || !in_array($column, $knownColumns, true) || !is_array($values)) {
            continue;
        }

        $clean = [];
        foreach ($values as $value) {
            if ($value === null) {
                $clean['blank'] = null;
            } elseif (is_string($value) && mb_strlen($value) <= 255) {
                $clean['v:' . $value] = $value;
            }
            if (count($clean) >= 500) {
                break;
            }
        }
        if ($clean !== []) {
            $result[$column] = array_values($clean);
        }
    }

    return $result;
}

/**
 * Pure. The header sort from the request: a known column and asc/desc, or none.
 *
 * @param list<string> $knownColumns
 * @return array{0: string, 1: string}
 */
function parse_column_sort(?string $column, ?string $direction, array $knownColumns): array
{
    $column = (string) $column;
    if (!in_array($column, $knownColumns, true)) {
        return ['', 'asc'];
    }

    return [$column, $direction === 'desc' ? 'desc' : 'asc'];
}

/**
 * WHERE conditions for the chosen column values. $expressions maps a column key to the SQL expression that yields
 * the value shown in that column (NULL = BLANKS). $excludeColumn leaves that column's own filter out, which is how
 * an Excel filter list works: it offers every value the OTHER filters still allow.
 *
 * @param array<string, list<?string>> $columns
 * @param array<string, string> $expressions
 * @return array{0: list<string>, 1: array<string, string>}
 */
function build_column_filter_conditions(array $columns, array $expressions, ?string $excludeColumn = null): array
{
    $conditions = [];
    $params = [];

    foreach ($columns as $column => $values) {
        if ($column === $excludeColumn || !isset($expressions[$column]) || !is_array($values) || $values === []) {
            continue;
        }

        $parts = [];
        $placeholders = [];
        foreach ($values as $i => $value) {
            if ($value === null) {
                $parts[] = $expressions[$column] . ' IS NULL';
            } else {
                $key = 'cf_' . $column . '_' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = (string) $value;
            }
        }
        if ($placeholders !== []) {
            $parts[] = $expressions[$column] . ' IN (' . implode(', ', $placeholders) . ')';
        }
        $conditions[] = '(' . implode(' OR ', $parts) . ')';
    }

    return [$conditions, $params];
}

/**
 * The distinct values of one column (with how many rows carry each) among the rows the other conditions allow,
 * for the Excel-style filter box. $fromSql is the FROM/JOIN part of the listing query. $search narrows the list;
 * at most $limit values come back (truncated = more exist). blank_count is how many rows have no value (only
 * counted without a search).
 *
 * @param array<string, string> $expressions
 * @param list<string> $conditions
 * @param array<string, mixed> $params
 * @return array{values: list<array{value: string, count: int}>, blank_count: int, truncated: bool}
 */
function get_column_value_list(PDO $pdo, string $fromSql, array $expressions, string $column, array $conditions, array $params, string $search = '', int $limit = 200): array
{
    if (!isset($expressions[$column])) {
        throw new InvalidArgumentException('Unknown column.');
    }
    $expr = $expressions[$column];

    $valueConditions = $conditions;
    $valueConditions[] = $expr . ' IS NOT NULL';
    $valueParams = $params;
    $search = trim($search);
    if ($search !== '') {
        $valueConditions[] = $expr . ' LIKE :cf_search';
        $valueParams['cf_search'] = '%' . addcslashes($search, '\\%_') . '%';
    }

    $stmt = $pdo->prepare(
        "SELECT {$expr} AS v, COUNT(*) AS c {$fromSql}
         WHERE " . implode(' AND ', $valueConditions) . '
         GROUP BY v ORDER BY v ASC LIMIT ' . ($limit + 1)
    );
    $stmt->execute($valueParams);
    $rows = $stmt->fetchAll();

    $truncated = count($rows) > $limit;
    $values = [];
    foreach (array_slice($rows, 0, $limit) as $row) {
        $values[] = ['value' => (string) $row['v'], 'count' => (int) $row['c']];
    }

    $blankCount = 0;
    if ($search === '') {
        $blankConditions = $conditions;
        $blankConditions[] = $expr . ' IS NULL';
        $blankStmt = $pdo->prepare('SELECT COUNT(*) ' . $fromSql . ' WHERE ' . implode(' AND ', $blankConditions));
        $blankStmt->execute($params);
        $blankCount = (int) $blankStmt->fetchColumn();
    }

    return ['values' => $values, 'blank_count' => $blankCount, 'truncated' => $truncated];
}

/**
 * A table header cell with its Excel-style filter button (assets/js/column_filters.js attaches to .col-filter-btn).
 */
function column_filter_header_cell(string $key, string $label): string
{
    return '<th class="px-4 py-3"><div class="flex items-center gap-1.5"><span>' . e($label) . '</span>'
        . '<button type="button" class="col-filter-btn rounded p-1 text-gray-400 hover:bg-gray-100 dark:hover:bg-white/10 hover:text-csc-navy-600 dark:hover:text-csc-navy-300" data-col="' . e($key) . '" data-label="' . e($label) . '" aria-label="Filter ' . e($label) . '" title="Filter / sort">'
        . '<svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4.5h18l-7 8.25V19l-4 1.5v-7.75L3 4.5z" /></svg></button></div></th>';
}
