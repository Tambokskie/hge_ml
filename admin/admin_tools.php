<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/gemini.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/app_settings.php';

require_role('administrator');

$pdo = get_pdo();
$geminiKey1Status = gemini_key_display_status($pdo, 'gemini_api_key', defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '');
$geminiKey2Status = gemini_key_display_status($pdo, 'gemini_api_key_2', defined('GEMINI_API_KEY_2') ? GEMINI_API_KEY_2 : '');
$geminiKey3Status = gemini_key_display_status($pdo, 'gemini_api_key_3', defined('GEMINI_API_KEY_3') ? GEMINI_API_KEY_3 : '');
$geminiKey4Status = gemini_key_display_status($pdo, 'gemini_api_key_4', defined('GEMINI_API_KEY_4') ? GEMINI_API_KEY_4 : '');

$currentUser = current_user();
$currentPage = 'admin_tools';
$csrfToken = csrf_token();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="lg:ml-64 min-h-screen bg-gray-50 dark:bg-surface-950">
    <div class="p-6 lg:p-8">
        <div class="mb-6 flex items-start gap-4">
            <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-csc-navy-600/10 text-csc-navy-600 dark:text-csc-navy-300">
                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" /></svg>
            </div>
            <div>
                <h1 class="text-2xl font-bold text-csc-navy-600 dark:text-white">ADMIN</h1>
                <p class="text-sm text-gray-600 dark:text-gray-500 mt-1">ADMINISTRATOR-ONLY TOOLS. SENSITIVE ACTIONS ASK YOU TO RE-ENTER YOUR CREDENTIALS FIRST.</p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div class="bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl p-6 hge-shadow motion-safe:animate-fade-in-up flex flex-col">
                <div class="flex items-center gap-3 mb-3">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-csc-navy-600/10 text-csc-navy-600 dark:bg-csc-navy-600/15 dark:text-csc-navy-300">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 0v3.75m-16.5-3.75v3.75m16.5 0v3.75C20.25 16.153 16.556 18 12 18s-8.25-1.847-8.25-4.125v-3.75m16.5 0c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125" /></svg>
                    </span>
                    <h2 class="text-base font-bold text-csc-navy-600 dark:text-white">DATABASE BACKUP</h2>
                </div>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-5 flex-1">DOWNLOAD A FULL COPY OF THE DATABASE AS A .SQL FILE. YOU MUST CONFIRM YOUR USERNAME AND PASSWORD BEFORE THE FILE IS GENERATED.</p>
                <div>
                    <button type="button" id="create-db-backup-btn" class="inline-flex items-center gap-1.5 rounded-xl border border-gray-300 dark:border-white/15 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 text-sm font-medium px-4 py-2.5 transition-colors">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                        CREATE DB BACKUP
                    </button>
                </div>
            </div>

            <div class="bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl p-6 hge-shadow motion-safe:animate-fade-in-up flex flex-col" style="animation-delay: 100ms">
                <div class="flex items-center gap-3 mb-3">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-yellow-500/10 text-yellow-600 dark:text-yellow-400">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" /></svg>
                    </span>
                    <h2 class="text-base font-bold text-csc-navy-600 dark:text-white">AI EXTRACTION SETTINGS</h2>
                </div>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-5 flex-1">VIEW OR CHANGE THE GEMINI API KEYS USED FOR AI EXTRACTION OF UPLOADED LISTS, INCLUDING THE QUOTA-FALLBACK KEYS. YOU MUST CONFIRM YOUR USERNAME AND PASSWORD FIRST.</p>
                <div>
                    <button type="button" id="configure-ai-settings-btn" class="inline-flex items-center gap-1.5 rounded-xl border border-gray-300 dark:border-white/15 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 text-sm font-medium px-4 py-2.5 transition-colors">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" /></svg>
                        AI EXTRACTION SETTINGS
                    </button>
                </div>
            </div>
        </div>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<script>
window.HGE_ADMIN = {
    csrfToken: <?= json_encode($csrfToken) ?>,
    apiBase: <?= json_encode(APP_BASE_URL) ?>,
    geminiKeyStatus: {
        key1: <?= json_encode($geminiKey1Status) ?>,
        key2: <?= json_encode($geminiKey2Status) ?>,
        key3: <?= json_encode($geminiKey3Status) ?>,
        key4: <?= json_encode($geminiKey4Status) ?>
    }
};
</script>
<script src="<?= e(asset_url('assets/js/admin_tools.js')) ?>"></script>
