<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (current_user() !== null) {
    redirect(APP_BASE_URL . '/admin/dashboard.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'YOUR SESSION EXPIRED. PLEASE TRY AGAIN.';
    } else {
        $user = attempt_login(get_pdo(), trim($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));

        if ($user === null) {
            $error = 'INVALID USERNAME OR PASSWORD.';
        } else {
            login_user($user);
            redirect(APP_BASE_URL . '/admin/dashboard.php');
        }
    }
}

$csrfToken = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HGE Database — Login</title>
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/app.css')) ?>">
</head>
<body class="bg-surface-950 text-gray-100 min-h-screen flex items-center justify-center px-4">
    <div class="w-full max-w-sm bg-surface-900 border border-white/10 rounded-xl p-8">
        <h1 class="text-lg font-semibold tracking-wide text-center mb-1">HGE DATABASE</h1>
        <p class="text-xs text-gray-500 text-center mb-6">HONOR GRADUATE ELIGIBILITY</p>

        <?php if ($error): ?>
            <div class="mb-4 rounded-lg border border-red-500/30 bg-red-500/10 text-red-400 text-sm px-3 py-2">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="post" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <div>
                <label class="block text-xs text-gray-400 mb-1" for="username">USERNAME</label>
                <input class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm focus:outline-none focus:border-accent-500" type="text" id="username" name="username" required autofocus>
            </div>
            <div>
                <label class="block text-xs text-gray-400 mb-1" for="password">PASSWORD</label>
                <input class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm focus:outline-none focus:border-accent-500" type="password" id="password" name="password" required>
            </div>
            <button class="w-full rounded-lg bg-accent-600 hover:bg-accent-500 text-white text-sm font-medium py-2 transition-colors" type="submit">SIGN IN</button>
        </form>
    </div>
</body>
</html>
