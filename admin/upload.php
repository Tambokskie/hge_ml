<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/schools.php';

require_role('administrator');

$pdo = get_pdo();
$activeSchools = get_active_schools_list($pdo);
$uploadedSchools = get_schools_with_uploads($pdo);
$currentUser = current_user();
$currentPage = 'upload';
$csrfToken = csrf_token();

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<main class="lg:ml-64 min-h-screen bg-gray-50 dark:bg-surface-950">
    <div class="p-6 lg:p-8">
        <div class="max-w-7xl mx-auto">
            <div class="mb-6 flex items-start gap-4">
                <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-csc-navy-600/10 text-csc-navy-600 dark:text-csc-navy-300">
                    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-csc-navy-600 dark:text-white">UPLOAD LATIN HONOR LIST</h1>
                    <p class="text-sm text-gray-600 dark:text-gray-500 mt-1">IMPORT AND EXTRACT LATIN HONOR GRADUATE RECORDS FROM A SCHOOL'S PDF LIST.</p>
                </div>
            </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-stretch">
            <div class="lg:col-span-2 space-y-6">
                <div class="bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl p-6 sm:p-8 hge-shadow motion-safe:animate-fade-in-up" style="animation-delay: 0ms">
                    <div class="flex items-start gap-3 mb-6">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-csc-navy-600/10 text-csc-navy-600 dark:text-csc-navy-300">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 8.25 12 3.75m0 0L7.5 8.25M12 3.75v12" /></svg>
                        </div>
                        <div>
                            <h2 class="text-base font-bold text-csc-navy-600 dark:text-white">UPLOAD DOCUMENT</h2>
                            <p class="text-xs text-gray-500 dark:text-gray-500 mt-0.5">SELECT A SCHOOL, CHOOSE THE SCHOOL YEAR, AND UPLOAD THE LATIN HONOR LIST PDF.</p>
                        </div>
                    </div>

                    <form id="upload-form" action="<?= e(APP_BASE_URL) ?>/api/upload_pdf.php" data-view-upload-url="<?= e(APP_BASE_URL) ?>/admin/view_upload.php" data-extract-url="<?= e(APP_BASE_URL) ?>/api/extract_pdf.php" method="post" enctype="multipart/form-data" class="space-y-5">
                        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

                        <div>
                            <label class="mb-1.5 block text-xs font-bold tracking-wide text-csc-navy-600 dark:text-csc-navy-300" for="school_id">SCHOOL <span class="text-csc-red-600">*</span></label>
                            <div class="flex items-center gap-2">
                                <div class="relative flex-1 min-w-0">
                                    <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-gray-400 dark:text-gray-500">
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" /></svg>
                                    </span>
                                    <select id="school_id" name="school_id" required
                                           class="w-full rounded-xl bg-gray-50 dark:bg-surface-800 border border-gray-200 dark:border-white/10 pl-10 pr-3 py-2.5 text-sm focus:outline-none focus:border-csc-navy-500 focus:ring-2 focus:ring-csc-navy-500/20">
                                        <option value="" disabled selected>SELECT A SCHOOL</option>
                                        <?php foreach ($activeSchools as $school): ?>
                                            <option value="<?= (int) $school['id'] ?>"><?= e_upper(format_school_display_name($school['school_name'], $school['campus'])) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <a href="<?= e(APP_BASE_URL) ?>/admin/schools.php" class="shrink-0 inline-flex items-center gap-1.5 rounded-xl border border-csc-navy-500/40 text-csc-navy-600 dark:text-csc-navy-300 hover:bg-csc-navy-500/10 px-3 py-2.5 text-xs font-semibold transition-colors">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                                    MANAGE SCHOOLS
                                </a>
                            </div>
                        </div>

                        <div>
                            <label class="mb-1.5 block text-xs font-bold tracking-wide text-csc-navy-600 dark:text-csc-navy-300" for="school_year">SCHOOL YEAR <span class="text-csc-red-600">*</span></label>
                            <div class="relative">
                                <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-gray-400 dark:text-gray-500">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
                                </span>
                                <input type="text" id="school_year" name="school_year" required placeholder="e.g. 2025"
                                       inputmode="numeric" pattern="[0-9]*" maxlength="4" autocomplete="off"
                                       class="w-full rounded-xl bg-gray-50 dark:bg-surface-800 border border-gray-200 dark:border-white/10 pl-10 pr-3 py-2.5 text-sm focus:outline-none focus:border-csc-navy-500 focus:ring-2 focus:ring-csc-navy-500/20">
                            </div>
                            <p class="text-xs text-gray-400 dark:text-gray-600 mt-1">ENTER THE YEAR THE GRADUATES COMPLETED THEIR DEGREE. ONLY NUMBERS ARE ACCEPTED.</p>
                        </div>

                        <div>
                            <label class="mb-1.5 block text-xs font-bold tracking-wide text-csc-navy-600 dark:text-csc-navy-300">LATIN HONOR LIST PDF <span class="text-csc-red-600">*</span></label>
                            <div id="dropzone" class="cursor-pointer rounded-xl border-2 border-dashed border-gray-300 dark:border-white/15 px-6 py-10 text-center hover:border-csc-navy-500 transition-colors">
                                <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-csc-navy-600/10 text-csc-navy-600 dark:text-csc-navy-300">
                                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 8.25 12 3.75m0 0L7.5 8.25M12 3.75v12" /></svg>
                                </div>
                                <p class="text-sm font-semibold text-gray-700 dark:text-gray-300">DRAG &amp; DROP YOUR PDF HERE</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">OR CLICK TO BROWSE</p>
                                <p class="text-xs text-gray-400 dark:text-gray-600 mt-2">PDF FILE ONLY &bull; MAXIMUM 20 MB</p>
                            </div>
                            <input type="file" id="pdf_file" name="pdf_file" accept="application/pdf" required class="hidden">
                            <p id="file-info" class="text-xs text-gray-500 dark:text-gray-400 mt-2"></p>
                        </div>

                        <button type="submit" id="upload-submit-btn" class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-csc-navy-600 hover:bg-csc-navy-500 text-white text-sm font-semibold py-3 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 8.25 12 3.75m0 0L7.5 8.25M12 3.75v12" /></svg>
                            UPLOAD &amp; EXTRACT RECORDS
                        </button>
                    </form>

                    <div id="upload-progress" class="hidden mt-4 rounded-lg border border-csc-navy-500/30 bg-csc-navy-500/10 px-4 py-3">
                        <div class="flex items-center justify-between gap-3 mb-2">
                            <span id="upload-progress-label" class="text-sm text-csc-navy-600 dark:text-csc-navy-300"></span>
                            <span id="upload-progress-percent" class="hidden shrink-0 text-xs font-semibold tabular-nums text-csc-navy-600 dark:text-csc-navy-300"></span>
                        </div>
                        <div class="relative h-2 w-full overflow-hidden rounded-full bg-csc-navy-500/20">
                            <div id="upload-progress-bar" class="absolute inset-y-0 left-0 h-full rounded-full bg-csc-navy-600 transition-[width] duration-300 ease-out" style="width: 0%"></div>
                        </div>
                    </div>
                </div>

                <div class="bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl p-6 sm:p-8 hge-shadow motion-safe:animate-fade-in-up" style="animation-delay: 100ms">
                    <div class="flex items-start gap-3 mb-6">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-csc-navy-600/10 text-csc-navy-600 dark:text-csc-navy-300">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18v-5.25m0 0a6.01 6.01 0 001.5-.189m-1.5.189a6.01 6.01 0 01-1.5-.189m3.75 7.478a12.06 12.06 0 01-4.5 0m3.75 2.383a14.406 14.406 0 01-3 0M14.25 18v-.192c0-.983.658-1.823 1.508-2.316a7.5 7.5 0 10-7.517 0c.85.493 1.509 1.333 1.509 2.316V18" /></svg>
                        </div>
                        <div>
                            <h2 class="text-base font-bold text-csc-navy-600 dark:text-white">HOW IT WORKS</h2>
                            <p class="text-xs text-gray-500 dark:text-gray-500 mt-0.5">GET YOUR LATIN HONOR LIST RECORDS IN JUST A FEW SIMPLE STEPS.</p>
                        </div>
                    </div>

                    <?php
                        $steps = [
                            ['icon' => 'M2.25 21h19.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21', 'label' => 'SELECT SCHOOL', 'desc' => 'CHOOSE THE SCHOOL WHERE THE LIST CAME FROM.'],
                            ['icon' => 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5', 'label' => 'SCHOOL YEAR', 'desc' => 'ENTER THE YEAR THE GRADUATES COMPLETED THEIR DEGREE.'],
                            ['icon' => 'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z', 'label' => 'DRAG AND DROP YOUR PDF', 'desc' => 'UPLOAD THE SCHOOL\'S LATIN HONOR LIST.'],
                            ['icon' => 'M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 8.25 12 3.75m0 0L7.5 8.25M12 3.75v12', 'label' => 'CLICK UPLOAD', 'desc' => 'SUBMIT THE FORM TO UPLOAD AND EXTRACT THE FILE.'],
                            ['icon' => 'M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.43.992a7.723 7.723 0 010 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.991a7.775 7.775 0 010-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.28zM15 12a3 3 0 11-6 0 3 3 0 016 0z', 'label' => 'REVIEW RECORDS', 'desc' => 'VERIFY THE EXTRACTED GRADUATE INFORMATION.'],
                            ['icon' => 'M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3', 'label' => 'SAVE RECORDS', 'desc' => 'ADD VERIFIED RECORDS TO THE HGE DATABASE.'],
                        ];
                    ?>
                    <div class="flex flex-col sm:flex-row sm:items-start gap-6 sm:gap-2">
                        <?php foreach ($steps as $i => $step): ?>
                            <div class="flex-1 flex sm:flex-col items-center text-center gap-3 sm:gap-2">
                                <div class="relative shrink-0">
                                    <div class="flex h-11 w-11 items-center justify-center rounded-full bg-csc-navy-600/10 text-csc-navy-600 dark:text-csc-navy-300">
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="<?= $step['icon'] ?>" /></svg>
                                    </div>
                                    <span class="absolute -top-1 -left-1 flex h-5 w-5 items-center justify-center rounded-full bg-csc-navy-600 text-[10px] font-bold text-white ring-2 ring-white dark:ring-surface-900"><?= $i + 1 ?></span>
                                </div>
                                <div class="text-left sm:text-center">
                                    <p class="text-xs font-bold text-gray-900 dark:text-white"><?= e($step['label']) ?></p>
                                    <p class="text-[11px] text-gray-500 dark:text-gray-500 mt-0.5"><?= e($step['desc']) ?></p>
                                </div>
                            </div>
                            <?php if ($i < count($steps) - 1): ?>
                                <div class="hidden sm:flex items-center justify-center pt-5 text-gray-300 dark:text-gray-700">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="flex flex-col bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl p-6 hge-shadow motion-safe:animate-fade-in-up" style="animation-delay: 200ms">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex items-center gap-2.5">
                        <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-csc-navy-600/10 text-csc-navy-600 dark:text-csc-navy-300">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" /></svg>
                        </div>
                        <h2 class="text-base font-bold text-csc-navy-600 dark:text-white">SCHOOLS</h2>
                    </div>
                    <span class="shrink-0 inline-flex items-center rounded-full bg-csc-navy-600/10 text-csc-navy-600 dark:text-csc-navy-300 px-2.5 py-1 text-xs font-semibold"><?= count($uploadedSchools) ?> SCHOOL<?= count($uploadedSchools) === 1 ? '' : 'S' ?></span>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-500 mt-1">VIEW AND MANAGE SCHOOLS WITH UPLOADED LATIN HONOR LISTS.</p>

                <?php if (!empty($uploadedSchools)): ?>
                <div class="relative mt-4">
                    <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-gray-400 dark:text-gray-500">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607Z" /></svg>
                    </span>
                    <input type="text" id="uploaded-schools-search" placeholder="SEARCH SCHOOLS..." class="w-full rounded-xl bg-gray-50 dark:bg-surface-800 border border-gray-200 dark:border-white/10 pl-9 pr-3 py-2 text-xs uppercase placeholder:text-gray-400 placeholder:normal-case dark:placeholder:text-gray-600 focus:border-csc-navy-500 focus:ring-2 focus:ring-csc-navy-500/20 focus:outline-none">
                </div>
                <?php endif; ?>

                <?php if (empty($uploadedSchools)): ?>
                    <p class="text-sm text-gray-600 dark:text-gray-500 text-center py-6">NO SCHOOLS UPLOADED YET.</p>
                <?php else: ?>
                    <ul id="uploaded-schools-list" class="mt-3 flex-1 min-h-0 divide-y divide-gray-100 dark:divide-white/5 overflow-y-auto">
                        <?php foreach ($uploadedSchools as $school): ?>
                            <?php $displayName = format_school_display_name($school['school_name'], $school['campus']); ?>
                            <li class="uploaded-school-item" data-search="<?= e_upper($displayName . ' ' . $school['address']) ?>">
                                <a href="<?= e(APP_BASE_URL) ?>/admin/schools.php" class="flex items-center gap-3 py-3 first:pt-0 last:pb-0 group">
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-csc-navy-600/10 text-csc-navy-600 dark:text-csc-navy-300">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" /></svg>
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <p class="text-sm font-medium text-gray-900 dark:text-white truncate"><?= e_upper($displayName) ?></p>
                                        <p class="text-xs text-gray-400 dark:text-gray-600 mt-0.5 truncate"><?= $school['address'] !== '' ? e_upper($school['address']) : '—' ?></p>
                                    </span>
                                    <svg class="h-4 w-4 shrink-0 text-gray-300 dark:text-gray-700 group-hover:text-csc-navy-500 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p id="uploaded-schools-empty" class="hidden text-sm text-gray-500 dark:text-gray-400 text-center py-6">NO SCHOOLS MATCH YOUR SEARCH.</p>
                    <a href="<?= e(APP_BASE_URL) ?>/admin/schools.php" class="mt-4 flex items-center justify-center gap-1.5 text-xs font-semibold text-csc-navy-600 dark:text-csc-navy-300 hover:underline">
                        VIEW ALL SCHOOLS
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17.25 8.25L21 12m0 0l-3.75 3.75M21 12H3" /></svg>
                    </a>
                <?php endif; ?>
            </div>
        </div>
        </div>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<script src="<?= e(asset_url('assets/js/upload.js')) ?>"></script>
