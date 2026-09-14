<aside id="sidebar" class="fixed inset-y-0 left-0 z-30 w-64 -translate-x-full lg:translate-x-0 transition-transform bg-surface-900 border-r border-white/10 flex flex-col">
    <div class="px-5 py-5 border-b border-white/10">
        <p class="text-sm font-semibold tracking-wide text-white">HGE DATABASE</p>
        <p class="text-[11px] text-gray-500 mt-0.5">HONOR GRADUATE ELIGIBILITY</p>
    </div>
    <nav class="flex-1 px-3 py-4 space-y-1 text-sm">
        <a href="<?= e(APP_BASE_URL) ?>/admin/dashboard.php" class="flex items-center gap-3 rounded-lg px-3 py-2 <?= sidebar_nav_class($currentPage, 'dashboard') ?>">
            DASHBOARD
        </a>
        <a href="<?= e(APP_BASE_URL) ?>/admin/lookup.php" class="flex items-center gap-3 rounded-lg px-3 py-2 <?= sidebar_nav_class($currentPage, 'lookup') ?>">
            HONOR GRADUATE LOOK-UP
        </a>
        <a href="<?= e(APP_BASE_URL) ?>/admin/upload.php" class="flex items-center gap-3 rounded-lg px-3 py-2 <?= sidebar_nav_class($currentPage, 'upload') ?>">
            UPLOAD LATIN HONOR LIST
        </a>
        <?php if (user_has_role($currentUser ?? null, 'administrator')): ?>
        <a href="<?= e(APP_BASE_URL) ?>/admin/users.php" class="flex items-center gap-3 rounded-lg px-3 py-2 <?= sidebar_nav_class($currentPage, 'users') ?>">
            USER MANAGEMENT
        </a>
        <!-- Task 10 adds ACTIVITY LOG link here -->
        <?php endif; ?>
    </nav>
    <div class="px-3 py-4 border-t border-white/10">
        <a href="<?= e(APP_BASE_URL) ?>/logout.php" class="flex items-center gap-3 rounded-lg px-3 py-2 text-gray-400 hover:bg-white/5 hover:text-white text-sm">
            LOGOUT
        </a>
    </div>
</aside>
<div id="sidebar-overlay" class="fixed inset-0 bg-black/50 z-20 hidden lg:hidden"></div>
