<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schools.php';
require_once __DIR__ . '/../includes/graduates.php';

require_login();

$pdo = get_pdo();
$schools = get_active_schools_list($pdo);
$years = get_distinct_graduation_years($pdo);

$schoolId = (isset($_GET['school_id']) && (int) $_GET['school_id'] > 0) ? (int) $_GET['school_id'] : null;
$year = (isset($_GET['year']) && (int) $_GET['year'] > 0) ? (int) $_GET['year'] : null;
$name = trim((string) ($_GET['name'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));

$filters = ['school_id' => $schoolId, 'graduation_year' => $year, 'name' => $name];
// The year filter defaults to ALL YEARS, so a search with nothing filled in is a
// valid search that lists everything: there is always a search to run.
$hasFilters = true;

$currentUser = current_user();
$currentPage = 'lookup';
$csrfToken = csrf_token();
$isAdmin = user_has_role($currentUser, 'administrator');

$searchResult = null;
$searchFailed = false;
if ($hasFilters) {
    try {
        $searchResult = search_graduates($pdo, $filters, $page);

        try {
            log_activity($pdo, $currentUser['id'] ?? null, 'search', build_search_log_details($filters));
        } catch (\Throwable $logError) {
            error_log('Activity log failed: ' . $logError->getMessage());
        }
    } catch (\Throwable $e) {
        error_log('Graduate search failed: ' . $e->getMessage());
        $searchFailed = true;
    }
}

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="lg:ml-64 min-h-screen bg-gray-50 dark:bg-surface-950">
    <div class="p-6 lg:p-8">
        <div class="mb-6 flex items-start gap-4">
            <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-csc-navy-600/10 text-csc-navy-600 dark:text-csc-navy-300">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607Z" /></svg>
            </div>
            <div>
                <h1 class="text-2xl font-bold text-csc-navy-600 dark:text-white">HONOR GRADUATE LOOK-UP</h1>
                <p class="text-sm text-gray-600 dark:text-gray-500 mt-1">SEARCH BY SCHOOL, GRADUATION YEAR, OR GRADUATE NAME TO VERIFY A HONOR GRADUATE RECORD.</p>
            </div>
        </div>

        <div class="bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl p-4 sm:p-6 mb-6 hge-shadow motion-safe:animate-fade-in-up">
            <form id="lookup-filter-form" method="get" class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                <div>
                    <label class="mb-1.5 block text-xs font-bold tracking-wide text-csc-navy-600 dark:text-csc-navy-300">SCHOOL</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-gray-400 dark:text-gray-500">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" /></svg>
                        </span>
                        <select name="school_id" id="filter-school" class="w-full rounded-xl bg-gray-50 dark:bg-surface-800 border border-gray-200 dark:border-white/10 pl-10 pr-3 py-2.5 text-sm focus:border-csc-navy-500 focus:ring-2 focus:ring-csc-navy-500/20 focus:outline-none">
                            <option value="">ALL SCHOOLS</option>
                            <?php foreach ($schools as $school): ?>
                                <option value="<?= (int) $school['id'] ?>" <?= $schoolId === (int) $school['id'] ? 'selected' : '' ?>><?= e_upper(format_school_display_name($school['school_name'], $school['campus'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-bold tracking-wide text-csc-navy-600 dark:text-csc-navy-300">GRADUATION YEAR</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-gray-400 dark:text-gray-500">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
                        </span>
                        <select name="year" id="filter-year" class="w-full rounded-xl bg-gray-50 dark:bg-surface-800 border border-gray-200 dark:border-white/10 pl-10 pr-3 py-2.5 text-sm focus:border-csc-navy-500 focus:ring-2 focus:ring-csc-navy-500/20 focus:outline-none">
                            <option value="">ALL YEARS</option>
                            <?php foreach ($years as $y): ?>
                                <option value="<?= (int) $y ?>" <?= $year === (int) $y ? 'selected' : '' ?>><?= (int) $y ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="sm:col-span-2">
                    <label class="mb-1.5 block text-xs font-bold tracking-wide text-csc-navy-600 dark:text-csc-navy-300">GRADUATE NAME</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-gray-400 dark:text-gray-500">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>
                        </span>
                        <input type="text" name="name" id="filter-name" value="<?= e($name) ?>" placeholder="SEARCH BY NAME..." class="w-full rounded-xl bg-gray-50 dark:bg-surface-800 border border-gray-200 dark:border-white/10 pl-10 pr-3 py-2.5 text-sm uppercase placeholder:text-gray-400 placeholder:normal-case dark:placeholder:text-gray-600 focus:border-csc-navy-500 focus:ring-2 focus:ring-csc-navy-500/20 focus:outline-none">
                    </div>
                </div>
                <div class="sm:col-span-4 flex flex-wrap items-center justify-between gap-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="submit" class="inline-flex items-center gap-1.5 rounded-xl bg-csc-navy-600 hover:bg-csc-navy-500 text-white text-sm font-semibold px-4 py-2.5 transition-colors">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607Z" /></svg>
                            SEARCH
                        </button>
                        <button type="button" id="lookup-clear-filters-btn" class="inline-flex items-center gap-1.5 rounded-xl border border-gray-300 dark:border-white/15 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 text-sm font-medium px-4 py-2.5 transition-colors">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                            CLEAR FILTERS
                        </button>
                        <?php if ($isAdmin): ?>
                        <button type="button" id="bulk-update-date-btn" class="inline-flex items-center gap-1.5 rounded-xl border border-csc-navy-500/40 text-csc-navy-600 dark:text-csc-navy-300 hover:bg-csc-navy-500/10 text-sm font-medium px-4 py-2.5 transition-colors">UPDATE GRADUATION DATE</button>
                        <a href="<?= e(APP_BASE_URL) ?>/admin/duplicates.php" class="inline-flex items-center gap-1.5 rounded-xl border border-yellow-500/40 text-yellow-700 dark:text-yellow-400 hover:bg-yellow-500/10 text-sm font-medium px-4 py-2.5 transition-colors">FIND DUPLICATES</a>
                        <?php endif; ?>
                    </div>
                    <span id="lookup-records-found" class="inline-flex items-center rounded-full bg-gray-100 dark:bg-white/5 px-3 py-1.5 text-xs font-semibold text-gray-600 dark:text-gray-400 <?= (!$hasFilters || $searchFailed) ? 'hidden' : '' ?>">
                        <?= (int) ($searchResult['pagination']['total_rows'] ?? 0) ?> RECORD<?= (int) ($searchResult['pagination']['total_rows'] ?? 0) === 1 ? '' : 'S' ?> FOUND
                    </span>
                </div>
            </form>
        </div>

        <div id="lookup-results">
            <?php if (!$hasFilters): ?>
                <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-surface-900 p-10 text-center text-gray-500 dark:text-gray-400 hge-shadow">
                    <p class="text-sm">SEARCH BY NAME, SCHOOL, OR YEAR TO FIND HONOR GRADUATE RECORDS.</p>
                </div>
            <?php elseif ($searchFailed): ?>
                <div class="rounded-xl border border-red-500/30 bg-red-500/10 p-10 text-center text-red-400 hge-shadow">
                    <p class="text-sm font-medium">THE SEARCH COULD NOT BE COMPLETED. PLEASE TRY AGAIN.</p>
                </div>
            <?php elseif (empty($searchResult['rows'])): ?>
                <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-surface-900 p-10 text-center text-gray-500 dark:text-gray-400 hge-shadow">
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">NO HONOR GRADUATE RECORD FOUND.</p>
                </div>
            <?php else: ?>
                <div class="bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl overflow-x-auto overflow-y-hidden hge-shadow">
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="text-sm font-semibold text-gray-900 dark:text-white border-b border-gray-200 dark:border-white/10">
                                <th class="px-4 py-3">GRADUATE NAME</th>
                                <th class="px-4 py-3">DEGREE/COURSE</th>
                                <th class="px-4 py-3">SCHOOL</th>
                                <th class="px-4 py-3">LATIN HONOR</th>
                                <th class="px-4 py-3">DATE OF GRADUATION</th>
                                <th class="px-4 py-3">HGE STATUS</th>
                                <th class="px-4 py-3">DATE APPLIED</th>
                                <th class="px-4 py-3">DATE APPROVED</th>
                                <th class="px-4 py-3 text-right">ACTION</th>
                            </tr>
                        </thead>
                        <tbody id="lookup-results-tbody">
                            <?php foreach ($searchResult['rows'] as $rowIndex => $row): ?>
                                <tr class="border-b border-gray-100 dark:border-white/5 hover:bg-gray-100 dark:hover:bg-white/5 motion-safe:animate-fade-in-up" style="animation-delay: <?= (int) ($rowIndex * 50) ?>ms">
                                    <td class="px-4 py-3"><?= e_upper($row['graduate_name']) ?><?php if (!empty($row['is_duplicate'])): ?> <span class="ml-1 inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold bg-yellow-500/15 text-yellow-700 dark:bg-yellow-500/10 dark:text-yellow-400" title="POSSIBLE DUPLICATE RECORD">DUPLICATE</span><?php endif; ?></td>
                                    <td class="px-4 py-3"><?= ($row['course'] ?? '') !== '' ? e_upper($row['course']) : '—' ?></td>
                                    <td class="px-4 py-3"><?= e_upper(format_school_display_name($row['school_name'], $row['campus'])) ?></td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold <?= latin_honor_pill_class($row['latin_honor']) ?>"><?= e($row['latin_honor']) ?></span>
                                        <?php if (extraction_source_label($row['source'] ?? null) !== ''): ?>
                                            <span class="ml-1 inline-block rounded px-1.5 py-0.5 text-[10px] font-medium bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-400" title="EXTRACTED VIA <?= e(extraction_source_label($row['source'])) ?>"><?= e(extraction_source_label($row['source'])) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3"><?= e(format_date_display($row['graduation_date'])) ?></td>
                                    <td class="px-4 py-3">
                                        <?php if ((int) ($row['has_approved'] ?? 0) === 1): ?>
                                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-green-500/15 text-green-700 dark:bg-green-500/10 dark:text-green-400">APPROVED</span>
                                        <?php elseif ((int) $row['has_applied'] === 1): ?>
                                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold bg-orange-500/15 text-orange-700 dark:bg-orange-500/10 dark:text-orange-400">PROCESSING</span>
                                        <?php else: ?>
                                            <span class="text-gray-400 dark:text-gray-600">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3"><?= (int) $row['has_applied'] === 1 ? e(format_date_display($row['application_date'])) : '—' ?></td>
                                    <td class="px-4 py-3"><?= (int) ($row['has_approved'] ?? 0) === 1 ? e(format_date_display($row['approved_at'])) : '—' ?></td>
                                    <td class="px-4 py-3">
                                        <?php $actionBtnIndex = 0; ?>
                                        <div class="flex items-center justify-end gap-1.5">
                                            <button type="button" class="view-graduate-btn rounded-lg bg-csc-navy-600/10 hover:bg-csc-navy-600/20 text-csc-navy-600 dark:text-csc-navy-300 p-1.5 motion-safe:animate-drop-in" style="animation-delay: <?= (int) ($rowIndex * 50 + ($actionBtnIndex++) * 70) ?>ms" title="View record"
                                                data-name="<?= e($row['graduate_name']) ?>"
                                                data-course="<?= e($row['course'] ?? '') ?>"
                                                data-school="<?= e(format_school_display_name($row['school_name'], $row['campus'])) ?>"
                                                data-honor="<?= e($row['latin_honor']) ?>"
                                                data-date="<?= e(format_date_display($row['graduation_date'])) ?>"
                                                data-year="<?= e((string) $row['graduation_year']) ?>"
                                                data-upload-id="<?= (int) $row['upload_id'] ?>"
                                                data-filename="<?= e($row['original_filename']) ?>"
                                                data-uploaded-at="<?= e_upper(date('F j, Y', strtotime($row['uploaded_at']))) ?>">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                            </button>
                                            <?php if ((int) $row['has_applied'] !== 1): ?>
                                            <button type="button" class="apply-graduate-btn rounded-lg bg-green-500/10 hover:bg-green-500/20 text-green-600 dark:text-green-400 p-1.5 motion-safe:animate-drop-in" style="animation-delay: <?= (int) ($rowIndex * 50 + ($actionBtnIndex++) * 70) ?>ms" title="Mark as applied"
                                                data-id="<?= (int) $row['id'] ?>"
                                                data-name="<?= e($row['graduate_name']) ?>">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                                            </button>
                                            <?php endif; ?>
                                            <?php if ($isAdmin && (int) $row['has_applied'] === 1 && (int) ($row['has_approved'] ?? 0) !== 1): ?>
                                            <button type="button" class="approve-graduate-btn rounded-lg bg-green-500/10 hover:bg-green-500/20 text-green-600 dark:text-green-400 p-1.5 motion-safe:animate-drop-in" style="animation-delay: <?= (int) ($rowIndex * 50 + ($actionBtnIndex++) * 70) ?>ms" title="Mark as approved"
                                                data-id="<?= (int) $row['id'] ?>"
                                                data-name="<?= e($row['graduate_name']) ?>">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                            </button>
                                            <?php endif; ?>
                                            <?php if ($isAdmin): ?>
                                            <button type="button" class="edit-graduate-btn rounded-lg bg-csc-navy-600/10 hover:bg-csc-navy-600/20 text-csc-navy-600 dark:text-csc-navy-300 p-1.5 motion-safe:animate-drop-in" style="animation-delay: <?= (int) ($rowIndex * 50 + ($actionBtnIndex++) * 70) ?>ms" title="Edit record"
                                                data-id="<?= (int) $row['id'] ?>"
                                                data-name="<?= e($row['graduate_name']) ?>"
                                                data-course="<?= e($row['course'] ?? '') ?>"
                                                data-honor="<?= e($row['latin_honor']) ?>"
                                                data-date="<?= e((string) $row['graduation_date']) ?>"
                                                data-year="<?= e((string) $row['graduation_year']) ?>">
                                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125" /></svg>
                                            </button>
                                            <button type="button" class="delete-graduate-btn rounded-lg bg-csc-red-600/10 hover:bg-csc-red-600/20 text-csc-red-600 dark:text-csc-red-400 p-1.5 motion-safe:animate-drop-in" style="animation-delay: <?= (int) ($rowIndex * 50 + ($actionBtnIndex++) * 70) ?>ms" title="Delete record"
                                                data-id="<?= (int) $row['id'] ?>"
                                                data-name="<?= e($row['graduate_name']) ?>">
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
                <?php
                    $pageInfo = $searchResult['pagination'];
                    $rangeStart = $pageInfo['total_rows'] === 0 ? 0 : $pageInfo['offset'] + 1;
                    $rangeEnd = min($pageInfo['offset'] + $pageInfo['per_page'], $pageInfo['total_rows']);
                ?>
                <div id="lookup-pagination" class="flex flex-wrap items-center justify-between gap-3 mt-4 text-sm text-gray-500 dark:text-gray-400">
                    <span>SHOWING <?= (int) $rangeStart ?> TO <?= (int) $rangeEnd ?> OF <?= (int) $pageInfo['total_rows'] ?> ENTRIES</span>
                    <div class="flex items-center gap-1.5">
                        <?php if ($pageInfo['total_pages'] > 1): ?>
                            <select class="lookup-page-select rounded-lg border border-gray-200 dark:border-white/10 bg-white dark:bg-surface-800 px-2 py-1.5 text-sm focus:border-csc-navy-500 focus:ring-2 focus:ring-csc-navy-500/20 focus:outline-none" aria-label="Jump to page">
                                <?php for ($p = 1; $p <= $pageInfo['total_pages']; $p++): ?>
                                    <option value="<?= $p ?>" <?= $p === $pageInfo['page'] ? 'selected' : '' ?>>PAGE <?= $p ?> OF <?= (int) $pageInfo['total_pages'] ?></option>
                                <?php endfor; ?>
                            </select>
                        <?php endif; ?>
                        <?php if ($pageInfo['page'] > 1): ?>
                            <button type="button" class="lookup-page-btn rounded-lg border border-gray-200 dark:border-white/10 p-1.5 hover:bg-gray-100 dark:hover:bg-white/5" data-page="<?= (int) $pageInfo['page'] - 1 ?>" aria-label="Previous page">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
                            </button>
                        <?php endif; ?>
                        <?php foreach (pagination_window($pageInfo['page'], $pageInfo['total_pages']) as $pageNum): ?>
                            <button type="button" class="lookup-page-btn min-w-[2.25rem] rounded-lg px-2.5 py-1.5 text-sm font-medium <?= $pageNum === $pageInfo['page'] ? 'bg-csc-navy-600 text-white' : 'border border-gray-200 dark:border-white/10 hover:bg-gray-100 dark:hover:bg-white/5' ?>" data-page="<?= (int) $pageNum ?>"><?= (int) $pageNum ?></button>
                        <?php endforeach; ?>
                        <?php if ($pageInfo['page'] < $pageInfo['total_pages']): ?>
                            <button type="button" class="lookup-page-btn rounded-lg border border-gray-200 dark:border-white/10 p-1.5 hover:bg-gray-100 dark:hover:bg-white/5" data-page="<?= (int) $pageInfo['page'] + 1 ?>" aria-label="Next page">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php include __DIR__ . '/../includes/pdf_drawer.php'; ?>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<script>
window.HGE_LOOKUP = {
    apiBase: <?= json_encode(APP_BASE_URL) ?>,
    csrfToken: <?= json_encode($csrfToken) ?>,
    isAdmin: <?= json_encode($isAdmin) ?>
};
</script>
<script src="<?= e(asset_url('assets/js/lookup.js')) ?>"></script>
