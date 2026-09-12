<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_login();

$stats = get_dashboard_stats(get_pdo());
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
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
