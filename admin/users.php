<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/users.php';

require_role('administrator');

$pdo = get_pdo();
$users = get_all_users($pdo);
$currentUser = current_user();
$currentPage = 'users';
$csrfToken = csrf_token();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="lg:ml-64 min-h-screen bg-gray-50 dark:bg-surface-950">
    <div class="p-6 lg:p-8">
        <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
            <div class="flex items-start gap-4">
                <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-csc-navy-600/10 text-csc-navy-600 dark:text-csc-navy-300">
                    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" /></svg>
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-csc-navy-600 dark:text-white">USER MANAGEMENT</h1>
                    <p class="text-sm text-gray-600 dark:text-gray-500 mt-1">ADD, EDIT, OR REMOVE ADMINISTRATOR AND USER ACCOUNTS.</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" id="create-user-btn" class="inline-flex items-center gap-1.5 rounded-xl bg-csc-navy-600 hover:bg-csc-navy-500 text-white text-sm font-semibold px-4 py-2.5 transition-colors">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    CREATE USER
                </button>
                <button type="button" id="create-db-backup-btn" class="inline-flex items-center gap-1.5 rounded-xl border border-gray-300 dark:border-white/15 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 text-sm font-medium px-4 py-2.5 transition-colors">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                    CREATE DB BACKUP
                </button>
            </div>
        </div>

        <div class="bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl overflow-x-auto hge-shadow">
            <table class="w-full text-sm text-left">
                <thead>
                    <tr class="text-sm font-semibold text-gray-900 dark:text-white border-b border-gray-200 dark:border-white/10">
                        <th class="px-4 py-3">NAME</th>
                        <th class="px-4 py-3">USERNAME</th>
                        <th class="px-4 py-3">EMAIL</th>
                        <th class="px-4 py-3">ROLE</th>
                        <th class="px-4 py-3">STATUS</th>
                        <th class="px-4 py-3 text-right">ACTION</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <?php $isSelf = (int) $user['id'] === (int) $currentUser['id']; ?>
                        <tr class="border-b border-gray-100 dark:border-white/5 hover:bg-gray-100 dark:hover:bg-white/5">
                            <td class="px-4 py-3"><?= e_upper($user['name']) ?></td>
                            <td class="px-4 py-3"><?= e($user['username']) ?></td>
                            <td class="px-4 py-3"><?= e($user['email']) ?></td>
                            <td class="px-4 py-3"><?= e_upper($user['role']) ?></td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold <?= $user['status'] === 'active' ? 'bg-green-500/15 text-green-700 dark:bg-green-500/10 dark:text-green-400' : 'bg-csc-red-600/10 text-csc-red-600 dark:text-csc-red-400' ?>"><?= e_upper($user['status']) ?></span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <button type="button" class="edit-user-btn rounded-lg bg-csc-navy-600/10 hover:bg-csc-navy-600/20 text-csc-navy-600 dark:text-csc-navy-300 p-1.5" title="Edit user"
                                        data-user-id="<?= (int) $user['id'] ?>"
                                        data-name="<?= e($user['name']) ?>"
                                        data-username="<?= e($user['username']) ?>"
                                        data-role="<?= e($user['role']) ?>"
                                        data-status="<?= e($user['status']) ?>"
                                        data-is-self="<?= $isSelf ? '1' : '0' ?>">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125" /></svg>
                                    </button>
                                    <?php if ($user['role'] === 'user'): ?>
                                    <button type="button" class="remove-user-btn rounded-lg bg-csc-red-600/10 hover:bg-csc-red-600/20 text-csc-red-600 dark:text-csc-red-400 p-1.5" title="Remove user"
                                        data-user-id="<?= (int) $user['id'] ?>"
                                        data-name="<?= e($user['name']) ?>">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<script>
window.HGE_USERS = {
    csrfToken: <?= json_encode($csrfToken) ?>,
    apiBase: <?= json_encode(APP_BASE_URL) ?>,
    currentUserId: <?= (int) $currentUser['id'] ?>
};
</script>
<script src="<?= e(asset_url('assets/js/users.js')) ?>"></script>
