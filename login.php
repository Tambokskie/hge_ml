<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (current_user() !== null) {
    redirect(APP_BASE_URL . '/admin/dashboard.php');
}

$error = null;

if (($_GET['timeout'] ?? '') === '1') {
    $error = 'YOUR SESSION EXPIRED DUE TO INACTIVITY. PLEASE LOG IN AGAIN.';
}

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
    <title>CSC-HGE VERIFY — Login</title>
    <link rel="icon" type="image/png" href="<?= e(asset_url('assets/images/csclogo.png')) ?>">
    <script>
    (function () {
        try {
            if (localStorage.getItem('hge_theme') === 'dark') {
                document.documentElement.classList.add('dark');
            }
        } catch (e) {}
    })();
    </script>
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/app.css')) ?>">
</head>
<body class="relative min-h-screen overflow-hidden bg-gray-50 text-gray-900 antialiased dark:bg-surface-950 dark:text-white">

    <!-- Animated background: soft blurred orbs + drifting particles. Purely
         CSS transform/opacity keyframes (GPU-friendly, no JS loop). The
         continuous ones are gated behind motion-safe: so prefers-reduced-motion
         disables them automatically; the base gradient wash stays either way. -->
    <div class="fixed inset-0 overflow-hidden" aria-hidden="true">
        <div class="absolute inset-0 bg-gradient-to-br from-gray-50 via-blue-50 to-white dark:from-surface-950 dark:via-[#0d0d18] dark:to-[#05050a]"></div>

        <div class="motion-safe:animate-fade-in">
            <div class="auth-orb -left-24 -top-24 h-72 w-72 bg-accent-400/25 motion-safe:animate-float-a dark:bg-accent-600/30 sm:h-96 sm:w-96"></div>
            <div class="auth-orb -right-20 top-1/3 h-64 w-64 bg-indigo-300/25 motion-safe:animate-float-b dark:bg-indigo-500/25 sm:h-[26rem] sm:w-[26rem]"></div>
            <div class="auth-orb -bottom-24 left-1/4 h-56 w-56 bg-blue-300/25 motion-safe:animate-float-c dark:bg-violet-500/20 sm:h-80 sm:w-80"></div>
            <div class="auth-orb bottom-1/4 right-1/4 h-40 w-40 bg-blue-300/25 motion-safe:animate-glow-pulse dark:bg-blue-500/20"></div>

            <span class="absolute left-[15%] top-[22%] h-1 w-1 rounded-full bg-accent-500/50 motion-safe:animate-drift dark:bg-accent-300/70" style="animation-delay:0s"></span>
            <span class="absolute left-[82%] top-[28%] h-1.5 w-1.5 rounded-full bg-blue-500/40 motion-safe:animate-drift dark:bg-blue-300/60" style="animation-delay:1.4s"></span>
            <span class="absolute left-[70%] top-[70%] h-1 w-1 rounded-full bg-blue-500/50 motion-safe:animate-drift dark:bg-violet-300/70" style="animation-delay:2.8s"></span>
            <span class="absolute left-[25%] top-[78%] h-1.5 w-1.5 rounded-full bg-accent-500/40 motion-safe:animate-drift dark:bg-accent-300/60" style="animation-delay:4.2s"></span>
            <span class="absolute left-[48%] top-[12%] h-1 w-1 rounded-full bg-blue-500/50 motion-safe:animate-drift dark:bg-blue-300/70" style="animation-delay:2s"></span>
        </div>
    </div>

    <main class="relative z-10 flex min-h-screen items-center justify-center px-4 py-10">
        <div class="auth-glass-card motion-safe:animate-fade-in-up">
            <div class="mb-7 flex flex-col items-center text-center">
                <img src="<?= e(asset_url('assets/images/csclogo.png')) ?>" alt="Civil Service Commission" class="mb-4 h-16 w-auto drop-shadow-lg sm:h-20">
                <h1 class="text-xl font-semibold tracking-wide text-gray-900 dark:text-white">CSC-HGE VERIFY</h1>
                <p class="mt-1 text-[11px] uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">Honor Graduate Eligibility Verification System</p>
            </div>

            <?php if ($error): ?>
                <div class="auth-error mb-5" role="alert">
                    <svg viewBox="0 0 24 24" fill="none" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true">
                        <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.5"/>
                        <path d="M12 8v5M12 16h.01" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                    <span><?= e($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="post" class="space-y-5">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                <div>
                    <label class="auth-label" for="username">USERNAME</label>
                    <div class="relative">
                        <span class="auth-field-icon">
                            <svg viewBox="0 0 24 24" fill="none" class="h-4 w-4" aria-hidden="true">
                                <circle cx="12" cy="8" r="3.5" stroke="currentColor" stroke-width="1.5"/>
                                <path d="M4.5 19.5c0-3.6 3.4-6 7.5-6s7.5 2.4 7.5 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                        </span>
                        <input class="auth-field" type="text" id="username" name="username" placeholder="Enter your username" required autofocus autocomplete="username">
                    </div>
                </div>

                <div>
                    <label class="auth-label" for="password">PASSWORD</label>
                    <div class="relative">
                        <span class="auth-field-icon">
                            <svg viewBox="0 0 24 24" fill="none" class="h-4 w-4" aria-hidden="true">
                                <rect x="5" y="10.5" width="14" height="9.5" rx="2" stroke="currentColor" stroke-width="1.5"/>
                                <path d="M8 10.5V7.8a4 4 0 1 1 8 0v2.7" stroke="currentColor" stroke-width="1.5"/>
                            </svg>
                        </span>
                        <input class="auth-field" type="password" id="password" name="password" placeholder="Enter your password" required autocomplete="current-password">
                        <button type="button" id="toggle-password" class="absolute inset-y-0 right-3 flex items-center rounded text-gray-400 transition-colors hover:text-gray-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent-400/60 dark:text-gray-500 dark:hover:text-gray-300" aria-label="Show password" aria-pressed="false">
                            <svg id="eye-open-icon" viewBox="0 0 24 24" fill="none" class="h-4 w-4" aria-hidden="true">
                                <path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z" stroke="currentColor" stroke-width="1.5"/>
                                <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.5"/>
                            </svg>
                            <svg id="eye-closed-icon" viewBox="0 0 24 24" fill="none" class="hidden h-4 w-4" aria-hidden="true">
                                <path d="M3 3l18 18M9.9 5.1A10.7 10.7 0 0 1 12 5c6 0 9.5 6.5 9.5 6.5a15.6 15.6 0 0 1-3.3 4.1M6.6 6.6C4 8.4 2.5 11.5 2.5 11.5S6 18 12 18c1 0 1.9-.15 2.8-.4M9.9 14.1a3 3 0 0 0 4.2-4.2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <button class="auth-btn-primary" type="submit">SIGN IN</button>
            </form>

            <div class="mt-5 text-center">
                <button type="button" id="forgot-password-btn" class="auth-link">Forgot password?</button>
            </div>

            <div class="mt-4 flex justify-center">
                <button id="theme-toggle" type="button" class="auth-theme-toggle" aria-label="Switch between dark mode and light mode">
                    <svg class="hidden h-4 w-4 dark:block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m8.485-15.485l-.707.707M5.222 18.778l-.707.707M21 12h-1M4 12H3m16.485 5.485l-.707-.707M6.929 6.929l-.707-.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                    <svg class="block h-4 w-4 dark:hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                </button>
            </div>
        </div>
    </main>

    <script src="<?= e(asset_url('assets/js/toast.js')) ?>"></script>
    <script src="<?= e(asset_url('assets/js/theme.js')) ?>"></script>
    <script src="<?= e(asset_url('assets/js/login.js')) ?>"></script>
</body>
</html>
