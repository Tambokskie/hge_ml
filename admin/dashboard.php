<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/uploads.php';

require_login();

$pdo = get_pdo();
$stats = get_dashboard_stats($pdo);
$recentUploads = get_recent_uploads($pdo, 5);
$currentUser = current_user();
$currentPage = 'dashboard';

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="lg:ml-64 min-h-screen bg-surface-950">
    <div class="p-6 lg:p-8">
        <h1 class="text-xl font-semibold text-white mb-6">DASHBOARD</h1>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-surface-900 border border-white/10 rounded-xl p-5">
                <p class="text-xs text-gray-500 mb-2">TOTAL GRADUATES</p>
                <p class="text-2xl font-semibold text-white"><?= number_format($stats['total_graduates']) ?></p>
            </div>
            <div class="bg-surface-900 border border-white/10 rounded-xl p-5">
                <p class="text-xs text-gray-500 mb-2">TOTAL SCHOOLS</p>
                <p class="text-2xl font-semibold text-white"><?= number_format($stats['total_schools']) ?></p>
            </div>
            <div class="bg-surface-900 border border-white/10 rounded-xl p-5">
                <p class="text-xs text-gray-500 mb-2">TOTAL PDF LISTS</p>
                <p class="text-2xl font-semibold text-white"><?= number_format($stats['total_uploads']) ?></p>
            </div>
            <div class="bg-surface-900 border border-white/10 rounded-xl p-5">
                <p class="text-xs text-gray-500 mb-2">CURRENT YEAR RECORDS</p>
                <p class="text-2xl font-semibold text-white"><?= number_format($stats['current_year_records']) ?></p>
                <p class="text-xs text-gray-600 mt-1"><?= $stats['current_year'] ? e((string) $stats['current_year']) : 'NO DATA YET' ?></p>
            </div>
        </div>

        <div class="mt-8">
            <h2 class="text-sm font-semibold text-gray-400 mb-3 tracking-wide">LATEST UPLOAD</h2>
            <div class="bg-surface-900 border border-white/10 rounded-xl overflow-x-auto">
                <?php if (empty($recentUploads)): ?>
                    <p class="text-sm text-gray-500 p-6 text-center">NO UPLOADS YET.</p>
                <?php else: ?>
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="text-xs text-gray-500 border-b border-white/10">
                                <th class="px-4 py-3">SCHOOL</th>
                                <th class="px-4 py-3">YEAR</th>
                                <th class="px-4 py-3">FILE NAME</th>
                                <th class="px-4 py-3">DATE UPLOADED</th>
                                <th class="px-4 py-3">RECORDS EXTRACTED</th>
                                <th class="px-4 py-3">ACTION</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentUploads as $upload): ?>
                                <tr class="border-b border-white/5 hover:bg-white/5">
                                    <td class="px-4 py-3"><?= e_upper($upload['school_name']) ?></td>
                                    <td class="px-4 py-3"><?= e($upload['school_year']) ?></td>
                                    <td class="px-4 py-3"><?= e($upload['original_filename']) ?></td>
                                    <td class="px-4 py-3"><?= e_upper(date('F j, Y', strtotime($upload['uploaded_at']))) ?></td>
                                    <td class="px-4 py-3"><?= (int) $upload['records_extracted'] ?> RECORDS</td>
                                    <td class="px-4 py-3">
                                        <a href="<?= e(APP_BASE_URL) ?>/admin/view_upload.php?id=<?= (int) $upload['id'] ?>" class="text-accent-400 hover:underline">VIEW</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
