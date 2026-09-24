<aside id="sidebar" class="fixed inset-y-0 left-0 z-30 w-64 -translate-x-full lg:translate-x-0 transition-transform bg-csc-navy-600 dark:bg-surface-900 border-r border-white/10 flex flex-col">
    <div class="px-5 py-5 border-b border-white/10 flex flex-col items-center text-center">
        <img src="<?= e(asset_url('assets/images/csclogo.png')) ?>" alt="Civil Service Commission" class="h-10 w-auto mb-2">
        <p class="text-sm font-semibold tracking-wide text-white">CSC-HGE VERIFY</p>
        <p class="text-[11px] text-white/60 dark:text-gray-500 mt-0.5">HONOR GRADUATE ELIGIBILITY VERIFICATION SYSTEM</p>
    </div>
    <nav class="flex-1 px-3 py-4 space-y-1 text-sm">
        <p class="px-3 pt-1 pb-1 text-[11px] font-semibold tracking-wide text-white/40 dark:text-gray-600">OVERVIEW</p>
        <a href="<?= e(APP_BASE_URL) ?>/admin/dashboard.php" class="flex items-center gap-3 rounded-lg px-3 py-2 <?= sidebar_nav_class($currentPage, 'dashboard') ?>">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" /></svg>
            <span>DASHBOARD</span>
        </a>

        <p class="px-3 pt-4 pb-1 text-[11px] font-semibold tracking-wide text-white/40 dark:text-gray-600">GRADUATE RECORDS</p>
        <a href="<?= e(APP_BASE_URL) ?>/admin/lookup.php" class="flex items-center gap-3 rounded-lg px-3 py-2 <?= sidebar_nav_class($currentPage, 'lookup') ?>">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607Z" /></svg>
            <span>HONOR GRADUATE LOOK-UP</span>
        </a>
        <?php if (user_has_role($currentUser ?? null, 'administrator')): ?>
        <a href="<?= e(APP_BASE_URL) ?>/admin/upload.php" class="flex items-center gap-3 rounded-lg px-3 py-2 <?= sidebar_nav_class($currentPage, 'upload') ?>">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 8.25 12 3.75m0 0L7.5 8.25M12 3.75v12" /></svg>
            <span>UPLOAD LATIN HONOR LIST</span>
        </a>
        <a href="<?= e(APP_BASE_URL) ?>/admin/school_records.php" class="flex items-center gap-3 rounded-lg px-3 py-2 <?= sidebar_nav_class($currentPage, 'school_records') ?>">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
            <span>SCHOOL RECORDS</span>
        </a>

        <p class="px-3 pt-4 pb-1 text-[11px] font-semibold tracking-wide text-white/40 dark:text-gray-600">ADMINISTRATION</p>
        <details class="group" <?= in_array($currentPage, ['users', 'activity_log', 'schools', 'ocr_courses'], true) ? 'open' : '' ?>>
            <summary class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 text-white/70 dark:text-gray-400 hover:bg-white/10 dark:hover:bg-white/5 hover:text-white dark:hover:text-white hover:translate-x-0.5 cursor-pointer list-none [&::-webkit-details-marker]:hidden transition-all duration-200 ease-out active:scale-[0.98]">
                <span class="flex items-center gap-3">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.43.992a7.723 7.723 0 010 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.991a7.775 7.775 0 010-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.28z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                    SETTINGS
                </span>
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 text-white/50 dark:text-gray-500 transition-transform duration-200 group-open:rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </summary>
            <div class="ml-6 mt-1 space-y-1 border-l border-white/10 pl-3">
                <a href="<?= e(APP_BASE_URL) ?>/admin/users.php" class="flex items-center gap-3 rounded-lg px-3 py-2 <?= sidebar_nav_class($currentPage, 'users') ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" /></svg>
                    <span>USER MANAGEMENT</span>
                </a>
                <a href="<?= e(APP_BASE_URL) ?>/admin/activity_log.php" class="flex items-center gap-3 rounded-lg px-3 py-2 <?= sidebar_nav_class($currentPage, 'activity_log') ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    <span>ACTIVITY LOG</span>
                </a>
                <a href="<?= e(APP_BASE_URL) ?>/admin/schools.php" class="flex items-center gap-3 rounded-lg px-3 py-2 <?= sidebar_nav_class($currentPage, 'schools') ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347M4.26 10.147a50.548 50.548 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.841c-.896.248-1.783.52-2.658.814M4.26 10.147a50.696 50.696 0 017.74-3.342M12 13.489a50.702 50.702 0 017.74-3.342" /></svg>
                    <span>SCHOOL MANAGEMENT</span>
                </a>
                <a href="<?= e(APP_BASE_URL) ?>/admin/ocr_courses.php" class="flex items-center gap-3 rounded-lg px-3 py-2 <?= sidebar_nav_class($currentPage, 'ocr_courses') ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" /></svg>
                    <span>OCR COURSE KEYWORDS</span>
                </a>
            </div>
        </details>
        <?php endif; ?>
    </nav>
    <div class="px-3 py-4 border-t border-white/10">
        <a href="<?= e(APP_BASE_URL) ?>/logout.php" class="flex items-center gap-3 rounded-lg px-3 py-2 text-white/70 dark:text-gray-400 hover:bg-white/10 dark:hover:bg-white/5 hover:text-white dark:hover:text-white hover:translate-x-0.5 text-sm transition-all duration-200 ease-out active:scale-[0.98]">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l3 3m0 0-3 3m3-3H3" /></svg>
            <span>LOGOUT</span>
        </a>
    </div>
</aside>
<div id="sidebar-overlay" class="fixed inset-0 bg-black/50 z-20 hidden lg:hidden"></div>
