<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('administrator');

$nextKey = (string) ($_GET['next'] ?? '');
$destination = step_up_destinations()[$nextKey] ?? null;

if ($destination === null) {
    flash_set('error', 'INVALID DESTINATION.');
    redirect(APP_BASE_URL . '/admin/dashboard.php');
}

$currentUser = current_user();
$currentPage = 'verify_identity';
$csrfToken = csrf_token();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="lg:ml-64 min-h-screen bg-gray-50 dark:bg-surface-950 flex items-center justify-center p-6">
    <div class="w-full max-w-md rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-surface-900 p-6 sm:p-8 text-center hge-shadow">
        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-accent-500/10 text-accent-600 dark:text-accent-300">
            <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 10-8 0v4h8z" /></svg>
        </div>
        <h1 class="text-base font-semibold text-gray-900 dark:text-white mb-2">CONFIRM ADMINISTRATOR IDENTITY</h1>
        <p class="text-sm text-gray-600 dark:text-gray-400 mb-6">FOR SECURITY, CONFIRM YOUR ADMINISTRATOR CREDENTIALS TO CONTINUE TO <?= e_upper($destination['label']) ?>.</p>

        <form id="verify-identity-form" class="text-left space-y-3">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="next" value="<?= e($nextKey) ?>">
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1" for="verify-username">USERNAME</label>
                <input id="verify-username" name="username" type="text" autocomplete="username"
                    class="w-full rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-1" for="verify-password">PASSWORD</label>
                <input id="verify-password" name="password" type="password" autocomplete="current-password"
                    class="w-full rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm">
            </div>
            <p id="verify-error" class="hidden text-xs text-red-500"></p>
            <div class="flex gap-3 pt-2">
                <a href="<?= e(APP_BASE_URL) ?>/admin/dashboard.php"
                    class="flex-1 text-center rounded-full border border-gray-300 dark:border-white/15 px-4 py-2.5 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 transition-colors">CANCEL</a>
                <button type="submit" id="verify-submit-btn"
                    class="flex-1 rounded-full px-4 py-2.5 text-sm font-medium bg-accent-600 hover:bg-accent-500 text-white transition-colors disabled:opacity-50 disabled:cursor-not-allowed">VERIFY IDENTITY</button>
            </div>
        </form>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<script>
window.HGE_VERIFY_IDENTITY = {
    apiBase: <?= json_encode(APP_BASE_URL) ?>
};
</script>
<script src="<?= e(asset_url('assets/js/verify_identity.js')) ?>"></script>
