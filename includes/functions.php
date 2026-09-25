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

/**
 * Multi-word Filipino surname prefixes that must stay glued to the word
 * after them when guessing the surname boundary on a name with no comma
 * ("DELA CRUZ, JUAN" is unambiguous; "DELA CRUZ JUAN" alone is not) —
 * without this, a naive first-word-only guess would cut a real surname
 * in half (treating "DELA" as the whole surname and "CRUZ" as a given
 * name).
 */
const GRADUATE_SURNAME_PREFIX_WORDS = [
    'DE', 'DEL', 'DELA', 'DELOS', 'DELAS', 'LA', 'LOS', 'LAS',
    'SAN', 'STA', 'STO', 'SANTA', 'SANTO', 'MC', 'MAC', 'VAN', 'VON', 'DER', 'DI',
];

/**
 * Best-effort split of a stored `graduate_name` ("LAST NAME, GIVEN NAME
 * MIDDLE NAME" when the extractor found a comma, or just "LAST GIVEN
 * MIDDLE" space-separated when it didn't — both forms occur across the
 * PDF/OCR layouts this app already handles) into the three columns the
 * Excel template uses, for display and editing on the Review Extracted
 * Records page.
 *
 * This is a heuristic, not a guarantee: with a comma, only the given/
 * middle boundary is a guess (the last word of the remainder is assumed
 * to be the middle name); without one, the surname boundary is guessed
 * too. Genuinely ambiguous names (e.g. a two-word given name with no
 * middle name at all) can come out wrong — that's expected and fine,
 * since a human reviews and can correct every row before SAVE RECORDS.
 *
 * @return array{last_name: string, given_name: string, middle_name: string}
 */
function split_graduate_name(string $fullName): array
{
    $fullName = trim(preg_replace('/\s+/', ' ', $fullName) ?? $fullName);
    if ($fullName === '') {
        return ['last_name' => '', 'given_name' => '', 'middle_name' => ''];
    }

    if (strpos($fullName, ',') !== false) {
        [$lastName, $remainder] = array_map('trim', explode(',', $fullName, 2));

        return ['last_name' => $lastName] + split_given_and_middle_name($remainder);
    }

    $words = explode(' ', $fullName);
    $surnameWordCount = 1;
    if (count($words) > 2 && in_array(mb_strtoupper($words[0]), GRADUATE_SURNAME_PREFIX_WORDS, true)) {
        $surnameWordCount = 2;
    }

    $lastName = implode(' ', array_slice($words, 0, $surnameWordCount));
    $remainder = implode(' ', array_slice($words, $surnameWordCount));

    return ['last_name' => $lastName] + split_given_and_middle_name($remainder);
}

/**
 * @return array{given_name: string, middle_name: string}
 */
function split_given_and_middle_name(string $remainder): array
{
    $remainder = trim($remainder);
    if ($remainder === '') {
        return ['given_name' => '', 'middle_name' => ''];
    }

    $words = explode(' ', $remainder);
    if (count($words) === 1) {
        return ['given_name' => $words[0], 'middle_name' => ''];
    }

    $middleName = array_pop($words);

    return ['given_name' => implode(' ', $words), 'middle_name' => $middleName];
}

/**
 * The inverse of split_graduate_name(): recombines the three review-form
 * fields back into the single `graduate_name` string everything else in
 * the app (search, storage, activity log) expects.
 */
function combine_graduate_name(string $lastName, string $givenName, string $middleName): string
{
    $lastName = trim($lastName);
    $firstAndMiddle = trim(preg_replace('/\s+/', ' ', trim($givenName . ' ' . $middleName)) ?? '');

    if ($lastName === '') {
        return $firstAndMiddle;
    }

    return $firstAndMiddle === '' ? $lastName : "{$lastName}, {$firstAndMiddle}";
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
    $totalUploads = (int) $pdo->query('SELECT COUNT(*) FROM latin_honor_uploads')->fetchColumn();
    $currentYear = $pdo->query('SELECT MAX(graduation_year) FROM latin_honor_graduates')->fetchColumn();

    $currentYearRecords = 0;
    if ($currentYear !== null) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM latin_honor_graduates WHERE graduation_year = :year');
        $stmt->execute(['year' => $currentYear]);
        $currentYearRecords = (int) $stmt->fetchColumn();
    }

    return [
        'total_graduates' => $totalGraduates,
        'total_schools' => $totalSchools,
        'total_uploads' => $totalUploads,
        'current_year' => $currentYear !== null ? (int) $currentYear : null,
        'current_year_records' => $currentYearRecords,
    ];
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
