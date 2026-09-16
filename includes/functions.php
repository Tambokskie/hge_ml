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
    if ($currentPage === $navPage) {
        return 'bg-accent-600/20 text-accent-300 border-l-2 border-accent-500';
    }

    return 'text-gray-400 hover:bg-white/5 hover:text-white border-l-2 border-transparent';
}

function get_distinct_graduation_years(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT DISTINCT graduation_year FROM latin_honor_graduates ORDER BY graduation_year DESC');
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function log_activity(PDO $pdo, ?int $userId, string $action, ?string $details = null): void
{
    $stmt = $pdo->prepare('INSERT INTO activity_log (user_id, action, details) VALUES (:user_id, :action, :details)');
    $stmt->execute([
        'user_id' => $userId,
        'action' => $action,
        'details' => $details,
    ]);

    $pdo->exec('DELETE FROM activity_log WHERE created_at < (NOW() - INTERVAL 6 MONTH)');
}
