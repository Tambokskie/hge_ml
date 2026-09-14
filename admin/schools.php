<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schools.php';

require_role('administrator');

$pdo = get_pdo();
$schools = get_all_schools_with_counts($pdo);
$currentUser = current_user();
$currentPage = 'schools';
$csrfToken = csrf_token();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="lg:ml-64 min-h-screen bg-surface-950">
    <div class="p-6 lg:p-8">
        <div class="flex items-center justify-between mb-6">
            <h1 class="text-xl font-semibold text-white">SCHOOLS MANAGEMENT</h1>
            <button type="button" id="create-school-btn" class="rounded-lg bg-accent-600 hover:bg-accent-500 text-white text-sm font-medium px-4 py-2">CREATE SCHOOL</button>
        </div>

        <div class="bg-surface-900 border border-white/10 rounded-xl overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead>
                    <tr class="text-xs text-gray-500 border-b border-white/10">
                        <th class="px-4 py-3">SCHOOL NAME</th>
                        <th class="px-4 py-3">STATUS</th>
                        <th class="px-4 py-3">UPLOADS</th>
                        <th class="px-4 py-3">GRADUATES</th>
                        <th class="px-4 py-3">ACTION</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($schools as $school): ?>
                        <tr class="border-b border-white/5 hover:bg-white/5">
                            <td class="px-4 py-3"><?= e_upper($school['school_name']) ?></td>
                            <td class="px-4 py-3">
                                <span class="<?= $school['status'] === 'active' ? 'text-green-400' : 'text-red-400' ?>"><?= e_upper($school['status']) ?></span>
                            </td>
                            <td class="px-4 py-3"><?= (int) $school['upload_count'] ?></td>
                            <td class="px-4 py-3"><?= (int) $school['graduate_count'] ?></td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <button type="button" class="edit-school-btn text-accent-400 hover:underline"
                                    data-school-id="<?= (int) $school['id'] ?>"
                                    data-name="<?= e($school['school_name']) ?>"
                                    data-status="<?= e($school['status']) ?>">EDIT</button>
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
window.HGE_SCHOOLS = {
    csrfToken: <?= json_encode($csrfToken) ?>,
    apiBase: <?= json_encode(APP_BASE_URL) ?>
};
</script>
<script src="<?= e(APP_BASE_URL) ?>/assets/js/schools.js"></script>
