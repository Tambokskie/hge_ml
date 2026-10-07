<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reports.php';

require_login();

$pdo = get_pdo();
$currentUser = current_user();
$currentPage = 'reports';
$csrfToken = csrf_token();

$graduationYears = get_distinct_graduation_years($pdo);
$defaultYear = $graduationYears[0] ?? (int) date('Y');

$filingReport = get_application_filing_report($pdo, $defaultYear);
$tierBreakdown = get_honor_tier_breakdown_for_year($pdo, $defaultYear);
$ranking = get_school_ranking_by_honor_count($pdo, $defaultYear);

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="lg:ml-64 min-h-screen bg-gray-50 dark:bg-surface-950">
    <div class="p-6 lg:p-8">
        <div class="max-w-6xl mx-auto">
            <div class="mb-6 flex items-start gap-4">
                <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-csc-navy-600/10 text-csc-navy-600 dark:text-csc-navy-300">
                    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" /></svg>
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-csc-navy-600 dark:text-white">REPORTS</h1>
                    <p class="text-sm text-gray-600 dark:text-gray-500 mt-1">YEAR-SCOPED SUMMARIES OF LATIN HONOR GRADUATES AND HONOR GRADUATE ELIGIBILITY FILINGS.</p>
                </div>
            </div>

            <!-- APPLICATION FILING REPORT -->
            <div class="bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl p-6 mb-6 hge-shadow">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                    <h2 class="text-lg font-bold text-csc-navy-600 dark:text-white">APPLICATION FILING REPORT</h2>
                    <div class="flex items-center gap-2">
                        <select id="filing-report-year" class="rounded-xl border border-gray-300 dark:border-white/15 bg-white dark:bg-surface-800 text-sm px-3 py-2">
                            <?php foreach ($graduationYears as $y): ?>
                                <option value="<?= (int) $y ?>" <?= $y === $defaultYear ? 'selected' : '' ?>><?= (int) $y ?></option>
                            <?php endforeach; ?>
                        </select>
                        <a id="filing-report-export" href="<?= e(APP_BASE_URL) ?>/api/export_application_filing_report.php?year=<?= (int) $defaultYear ?>" class="inline-flex items-center gap-1.5 rounded-xl border border-gray-300 dark:border-white/15 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 text-sm font-medium px-4 py-2.5 transition-colors">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                            DOWNLOAD XLSX
                        </a>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
                    <div class="rounded-xl bg-gray-50 dark:bg-white/5 p-4">
                        <p class="text-xs text-gray-500 dark:text-gray-400">ON RECORD</p>
                        <p id="filing-total-on-record" class="text-2xl font-bold text-csc-navy-600 dark:text-white"><?= (int) $filingReport['total_on_record'] ?></p>
                    </div>
                    <div class="rounded-xl bg-gray-50 dark:bg-white/5 p-4">
                        <p class="text-xs text-gray-500 dark:text-gray-400">APPLIED</p>
                        <p id="filing-total-applied" class="text-2xl font-bold text-csc-navy-600 dark:text-white"><?= (int) $filingReport['total_applied'] ?></p>
                    </div>
                    <div class="rounded-xl bg-gray-50 dark:bg-white/5 p-4">
                        <p class="text-xs text-gray-500 dark:text-gray-400">FILING RATE</p>
                        <p id="filing-rate" class="text-2xl font-bold text-csc-navy-600 dark:text-white"><?= e((string) $filingReport['filing_rate']) ?>%</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-5">
                    <div class="rounded-xl border border-gray-100 dark:border-white/5 p-3 text-center">
                        <p class="text-xs text-gray-500 dark:text-gray-400">CUM LAUDE</p>
                        <p id="tier-cum-laude" class="text-lg font-semibold text-gray-900 dark:text-white"><?= (int) $tierBreakdown['CUM LAUDE'] ?></p>
                    </div>
                    <div class="rounded-xl border border-gray-100 dark:border-white/5 p-3 text-center">
                        <p class="text-xs text-gray-500 dark:text-gray-400">MAGNA CUM LAUDE</p>
                        <p id="tier-magna-cum-laude" class="text-lg font-semibold text-gray-900 dark:text-white"><?= (int) $tierBreakdown['MAGNA CUM LAUDE'] ?></p>
                    </div>
                    <div class="rounded-xl border border-gray-100 dark:border-white/5 p-3 text-center">
                        <p class="text-xs text-gray-500 dark:text-gray-400">SUMMA CUM LAUDE</p>
                        <p id="tier-summa-cum-laude" class="text-lg font-semibold text-gray-900 dark:text-white"><?= (int) $tierBreakdown['SUMMA CUM LAUDE'] ?></p>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="text-xs text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-white/10">
                            <tr>
                                <th class="px-4 py-3">SCHOOL</th>
                                <th class="px-4 py-3">ON RECORD</th>
                                <th class="px-4 py-3">APPLIED</th>
                                <th class="px-4 py-3">FILING RATE</th>
                            </tr>
                        </thead>
                        <tbody id="filing-report-tbody">
                            <?php if (empty($filingReport['schools'])): ?>
                                <tr><td colspan="4" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">NO HONOR GRADUATE RECORDS FOUND FOR THIS YEAR.</td></tr>
                            <?php else: ?>
                                <?php foreach ($filingReport['schools'] as $school): ?>
                                    <tr class="border-b border-gray-100 dark:border-white/5">
                                        <td class="px-4 py-3"><?= e_upper(format_school_display_name($school['school_name'], $school['campus'])) ?></td>
                                        <td class="px-4 py-3"><?= (int) $school['on_record'] ?></td>
                                        <td class="px-4 py-3"><?= (int) $school['applied'] ?></td>
                                        <td class="px-4 py-3"><?= e((string) $school['filing_rate']) ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- SCHOOL RANKING REPORT -->
            <div class="bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl p-6 hge-shadow">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                    <h2 class="text-lg font-bold text-csc-navy-600 dark:text-white">SCHOOL RANKING REPORT</h2>
                    <div class="flex items-center gap-2">
                        <select id="ranking-report-year" class="rounded-xl border border-gray-300 dark:border-white/15 bg-white dark:bg-surface-800 text-sm px-3 py-2">
                            <?php foreach ($graduationYears as $y): ?>
                                <option value="<?= (int) $y ?>" <?= $y === $defaultYear ? 'selected' : '' ?>><?= (int) $y ?></option>
                            <?php endforeach; ?>
                        </select>
                        <a id="ranking-report-export" href="<?= e(APP_BASE_URL) ?>/api/export_school_ranking_report.php?year=<?= (int) $defaultYear ?>" class="inline-flex items-center gap-1.5 rounded-xl border border-gray-300 dark:border-white/15 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 text-sm font-medium px-4 py-2.5 transition-colors">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                            DOWNLOAD XLSX
                        </a>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="text-xs text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-white/10">
                            <tr>
                                <th class="px-4 py-3">RANK</th>
                                <th class="px-4 py-3">SCHOOL</th>
                                <th class="px-4 py-3">LATIN HONOR GRADUATES</th>
                            </tr>
                        </thead>
                        <tbody id="ranking-report-tbody">
                            <?php if (empty($ranking)): ?>
                                <tr><td colspan="3" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">NO HONOR GRADUATE RECORDS FOUND FOR THIS YEAR.</td></tr>
                            <?php else: ?>
                                <?php foreach ($ranking as $index => $school): ?>
                                    <tr class="border-b border-gray-100 dark:border-white/5">
                                        <td class="px-4 py-3"><?= (int) ($index + 1) ?></td>
                                        <td class="px-4 py-3"><?= e_upper(format_school_display_name($school['school_name'], $school['campus'])) ?></td>
                                        <td class="px-4 py-3"><?= (int) $school['honor_count'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<script>
window.HGE_REPORTS = {
    apiBase: <?= json_encode(APP_BASE_URL) ?>,
    csrfToken: <?= json_encode($csrfToken) ?>
};
</script>
<script src="<?= e(asset_url('assets/js/reports.js')) ?>"></script>
