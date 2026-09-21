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
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-xl font-semibold text-gray-900 dark:text-white">USER MANAGEMENT</h1>
            <div class="flex gap-2">
                <button type="button" id="create-user-btn" class="rounded-lg bg-accent-600 hover:bg-accent-500 text-white text-sm font-medium px-4 py-2">CREATE USER</button>
                <button type="button" id="create-db-backup-btn" class="rounded-lg border border-gray-200 dark:border-white/10 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 text-sm font-medium px-4 py-2">CREATE DB BACKUP</button>
            </div>
        </div>

        <div class="bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead>
                    <tr class="text-xs text-gray-600 dark:text-gray-500 border-b border-gray-200 dark:border-white/10">
                        <th class="px-4 py-3">NAME</th>
                        <th class="px-4 py-3">USERNAME</th>
                        <th class="px-4 py-3">EMAIL</th>
                        <th class="px-4 py-3">ROLE</th>
                        <th class="px-4 py-3">STATUS</th>
                        <th class="px-4 py-3">ACTION</th>
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
                                <span class="<?= $user['status'] === 'active' ? 'text-green-400' : 'text-red-400' ?>"><?= e_upper($user['status']) ?></span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <button type="button" class="edit-user-btn text-accent-400 hover:underline"
                                    data-user-id="<?= (int) $user['id'] ?>"
                                    data-name="<?= e($user['name']) ?>"
                                    data-username="<?= e($user['username']) ?>"
                                    data-role="<?= e($user['role']) ?>"
                                    data-status="<?= e($user['status']) ?>"
                                    data-is-self="<?= $isSelf ? '1' : '0' ?>">EDIT</button>
                                <?php if ($user['role'] === 'user'): ?>
                                <button type="button" class="remove-user-btn text-red-600 dark:text-red-400 hover:underline ml-3"
                                    data-user-id="<?= (int) $user['id'] ?>"
                                    data-name="<?= e($user['name']) ?>">REMOVE</button>
                                <?php endif; ?>
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
