function formatCount(value) {
    return Math.round(value).toLocaleString('en-US');
}

// Polls api/extraction_progress.php while a long-running extract_pdf.php
// request is in flight, feeding each {percent, label} snapshot to onUpdate
// (see showExtractionProgressModal()). progressUrl is that endpoint's full
// URL with no query string (callers derive it the same way they already
// derive install_ocr_tools.php's URL: extractUrl.replace('extract_pdf.php',
// 'extraction_progress.php')). Returns a stop() to call once the main
// extract_pdf.php fetch settles — a dangling interval would otherwise keep
// polling (and briefly show a stale/empty snapshot) after the modal that
// update() targets is already gone.
function startExtractionProgressPolling(progressUrl, uploadId, onUpdate) {
    var stopped = false;

    function poll() {
        if (stopped) {
            return;
        }
        fetch(progressUrl + '?upload_id=' + encodeURIComponent(uploadId))
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!stopped && data && typeof data.percent === 'number') {
                    onUpdate(data.percent, data.label || '');
                }
            })
            .catch(function () { /* a missed poll just waits for the next tick */ });
    }

    poll();
    var intervalId = setInterval(poll, 700);

    return function stop() {
        stopped = true;
        clearInterval(intervalId);
    };
}

// Runs api/extract_pdf.php for one upload until it is done, without waiting for the
// admin to click CONTINUE EXTRACTION between 2-page batches. A batch that fails is
// sent again, up to MAX_TRIES tries in all (not for a used-up AI quota — another try
// cannot help); once a batch succeeds the counter starts over for the next one.
// Resolves with the last response (status success with has_more false, or the error
// of a batch that failed every try; ocr_* statuses are passed straight through).
// Rejects only if the server could not be reached at all on every try.
//   url, fields {csrf_token, upload_id, ...first-request fields}, continuing (start
//   from a later batch), onRetry(tryNumber, maxTries, message), onBatch().
function runChunkedExtraction(options) {
    var MAX_TRIES = 3;

    function send(continuing, tryNumber) {
        var formData = new FormData();
        Object.keys(options.fields).forEach(function (key) {
            // reextract/use_ocr belong to the first request only
            if (continuing && (key === 'reextract' || key === 'use_ocr')) {
                return;
            }
            formData.set(key, options.fields[key]);
        });
        if (continuing) {
            formData.set('continue', '1');
        }

        function retryOrGiveUp(message, fallback) {
            if (tryNumber < MAX_TRIES) {
                if (options.onRetry) {
                    options.onRetry(tryNumber + 1, MAX_TRIES, message);
                }
                return send(continuing, tryNumber + 1);
            }
            return fallback();
        }

        return fetch(options.url, { method: 'POST', body: formData })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.status === 'success' && data.has_more) {
                    if (options.onBatch) {
                        options.onBatch();
                    }
                    return send(true, 1);
                }
                var message = String(data.message || '');
                if (data.status === 'error' && /TRY/.test(message) && !/QUOTA/.test(message)) {
                    return retryOrGiveUp(message, function () { return data; });
                }
                return data;
            }, function (error) {
                return retryOrGiveUp('', function () { throw error; });
            });
    }

    return send(!!options.continuing, 1);
}

// Eases `el`'s text content from `from` (default 0) up to `target` over
// `duration` ms, calling `onComplete` (if given) once it lands exactly on
// `target`. The default `from` of 0 serves the once-per-load entrance
// animation (e.g. the Dashboard's UPLOAD ACTIVITY card, which then reveals
// its line graph only once the count finishes); passing the previous value
// as `from` lets a later re-count (e.g. switching the Dashboard year
// dropdown) tween between two arbitrary values instead of flashing to 0.
function animateCountUp(el, target, duration, onComplete, from) {
    var startValue = from || 0;
    var startTime = null;
    // A newer call on the same element (e.g. year switched again mid-count)
    // supersedes this one, so two loops never fight over the text.
    var token = el.__countToken = (el.__countToken || 0) + 1;

    function step(timestamp) {
        if (el.__countToken !== token) {
            return;
        }
        if (startTime === null) {
            startTime = timestamp;
        }
        var progress = Math.min((timestamp - startTime) / duration, 1);
        var eased = 1 - Math.pow(1 - progress, 3);
        el.textContent = formatCount(startValue + (target - startValue) * eased);
        if (progress < 1) {
            requestAnimationFrame(step);
        } else {
            el.textContent = formatCount(target);
            if (onComplete) {
                onComplete();
            }
        }
    }

    requestAnimationFrame(step);
}

// Fade-in count-up for any ".dashboard-stat-number" element (data-count-to
// gives its target). Self-initializing and page-agnostic so any page can opt
// a stat card into the same entrance animation as the Dashboard's — see
// admin/dashboard.php and admin/school_records.php for the markup pattern
// (a ".dashboard-stat-card" wrapper with "motion-safe:animate-fade-in-up"
// and a staggered inline animation-delay, containing this number).
//
// ".dashboard-stat-reveal" is the same fade-in timing/stagger for a stat
// card's secondary details — a denominator ("/ 21") or a caption line ("1
// SCHOOL WITH NO SUBMISSION YET") — so the whole card settles in together
// instead of just its headline number. It counts up too when it carries its
// own data-count-to (e.g. the denominator); otherwise it just fades in, since
// "font-variant-numeric" prose isn't something you count up character by
// character.
document.addEventListener('DOMContentLoaded', function () {
    var prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    var revealTargets = Array.prototype.slice.call(
        document.querySelectorAll('.dashboard-stat-number, .dashboard-stat-reveal')
    );

    revealTargets.forEach(function (el, index) {
        var hasCount = el.hasAttribute('data-count-to');
        var target = hasCount ? (parseInt(el.dataset.countTo, 10) || 0) : null;

        if (prefersReducedMotion) {
            if (hasCount) {
                el.textContent = formatCount(target);
            }
            el.classList.add('is-visible');
            return;
        }

        setTimeout(function () {
            el.classList.add('is-visible');
            if (hasCount) {
                animateCountUp(el, target, 1200);
            }
        }, index * 120);
    });
});

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

// Shared icon path data, rendered at different sizes for a modal's header icon (h-7 w-7),
// a form field's prefix icon (h-5 w-5), or an inline button icon (h-4 w-4).
var ICON_PATHS = {
    building: '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" />',
    location: '<path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />',
    mail: '<path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />',
    lock: '<path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />',
    user: '<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />',
    calendar: '<path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />',
    tag: '<path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.83.699 2.528 0l4.318-4.318a1.788 1.788 0 000-2.528l-9.581-9.581A2.25 2.25 0 009.568 3z" /><path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6z" />',
    academic: '<path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" />',
    hash: '<path stroke-linecap="round" stroke-linejoin="round" d="M5.25 8.25h15m-16.5 7.5h15m-1.8-15l-3.9 19.5m-2.1-19.5l-3.9 19.5" />',
    pen: '<path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125" />',
    plus: '<path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />',
    check: '<path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />',
    trash: '<path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />',
    download: '<path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" />',
    eye: '<path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />',
    reset: '<path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />',
    search: '<path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607Z" />',
    chevronLeft: '<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" />',
    chevronRight: '<path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />',
    document: '<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />',
    info: '<path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z" />',
    externalLink: '<path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />',
    warning: '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />',
};

function svgIcon(name, sizeClass, strokeWidth) {
    return '<svg class="' + sizeClass + '" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="' + (strokeWidth || 1.5) + '">' + (ICON_PATHS[name] || '') + '</svg>';
}

// A modal header's icon circle (h-7 w-7).
function modalHeaderIcon(name) {
    return svgIcon(name, 'h-7 w-7', 2);
}

// A form field's left-inset prefix icon (h-5 w-5). Used by modalField()'s `icon` option.
function modalFieldIcon(name) {
    return svgIcon(name, 'h-5 w-5', 1.5);
}

// An inline icon inside a modal footer button (h-4 w-4). Used by showModal()'s button `icon` option.
function modalButtonIcon(name) {
    return svgIcon(name, 'h-4 w-4', 2);
}

// A small icon-only action button, e.g. a table row's view/edit icons (h-4 w-4, thinner stroke).
function actionIcon(name) {
    return svgIcon(name, 'h-4 w-4', 1.75);
}

// Up to `windowSize` page numbers to render as pagination pills, centered on the current
// page (mirrors includes/functions.php's lookup_pagination_window() for the server-rendered table).
function paginationWindow(page, totalPages, windowSize) {
    windowSize = windowSize || 5;
    if (totalPages <= windowSize) {
        var all = [];
        for (var p = 1; p <= totalPages; p++) {
            all.push(p);
        }
        return all;
    }

    var half = Math.floor(windowSize / 2);
    var start = Math.max(1, Math.min(page - half, totalPages - windowSize + 1));
    var pages = [];
    for (var i = 0; i < windowSize; i++) {
        pages.push(start + i);
    }
    return pages;
}

// Mirrors includes/functions.php's validate_removal_reason(): letters/digits/
// spaces only (so a punctuation-only answer like "-" or "N/A" already fails
// on the "/" or "-") plus at least one letter, for instant feedback before
// the request round-trip. The server re-validates authoritatively.
function isValidRemovalReason(value) {
    var trimmed = String(value || '').trim();
    return trimmed !== '' && /^[A-Za-z0-9 ]+$/.test(trimmed) && /[A-Za-z]/.test(trimmed);
}

// Tailwind classes for a Latin Honor pill badge, color-coded by tier
// (mirrors includes/functions.php's latin_honor_pill_class() for the server-rendered table).
function honorPillClass(honor) {
    var normalized = String(honor || '').trim().toUpperCase();
    if (normalized === 'SUMMA CUM LAUDE') {
        return 'bg-amber-500/15 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400';
    }
    if (normalized === 'MAGNA CUM LAUDE') {
        return 'bg-csc-navy-600/10 text-csc-navy-600 dark:bg-csc-navy-600/15 dark:text-csc-navy-300';
    }
    return 'bg-green-500/15 text-green-700 dark:bg-green-500/10 dark:text-green-400';
}

// Mirrors includes/functions.php's extraction_source_label(): '' for a row
// saved before extraction source was tracked, so the caller renders nothing
// rather than an "UNKNOWN" badge for thousands of pre-existing rows.
function extractionSourceLabel(source) {
    switch (source) {
        case 'ai': return 'AI';
        case 'ocr': return 'OCR';
        case 'text': return 'TEXT';
        case 'manual': return 'MANUAL';
        default: return '';
    }
}

// Label + Tailwind classes for an upload's extraction_status pill (mirrors
// includes/functions.php's upload_status_pill() for the AJAX-rendered table
// on admin/school_records.php).
var UPLOAD_STATUS_PILLS = {
    pending: { label: 'PENDING EXTRACTION', className: 'bg-gray-500/15 text-gray-700 dark:bg-gray-500/10 dark:text-gray-400' },
    extracted: { label: 'EXTRACTED', className: 'bg-csc-navy-600/10 text-csc-navy-600 dark:bg-csc-navy-600/15 dark:text-csc-navy-300' },
    draft: { label: 'DRAFT', className: 'bg-yellow-500/15 text-yellow-700 dark:bg-yellow-500/10 dark:text-yellow-400' },
    reviewed: { label: 'REVIEWED', className: 'bg-green-500/15 text-green-700 dark:bg-green-500/10 dark:text-green-400' },
    failed: { label: 'EXTRACTION FAILED', className: 'bg-yellow-500/15 text-yellow-700 dark:bg-yellow-500/10 dark:text-yellow-400' },
};

function uploadStatusPill(status) {
    return UPLOAD_STATUS_PILLS[status] || { label: String(status || '').toUpperCase(), className: 'bg-gray-500/15 text-gray-700 dark:bg-gray-500/10 dark:text-gray-400' };
}

// Whole days elapsed since an ISO-ish "YYYY-MM-DD HH:MM:SS" datetime string
// (mirrors includes/functions.php's days_since()).
function daysSince(datetime) {
    var timestamp = new Date(String(datetime).replace(' ', 'T')).getTime();
    if (isNaN(timestamp)) {
        return 0;
    }
    return Math.floor((Date.now() - timestamp) / 86400000);
}

// Shared footer button styles for form-style modals (showModal() with a `subtitle`).
var MODAL_BTN = {
    secondary: 'inline-flex items-center justify-center gap-1.5 rounded-xl border border-gray-300 dark:border-white/15 px-4 py-2.5 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 transition-colors',
    primary: 'inline-flex items-center justify-center gap-1.5 rounded-xl bg-csc-navy-600 hover:bg-csc-navy-500 px-4 py-2.5 text-sm font-semibold text-white transition-colors',
    danger: 'inline-flex items-center justify-center gap-1.5 rounded-xl bg-csc-red-600 hover:bg-csc-red-500 px-4 py-2.5 text-sm font-semibold text-white transition-colors',
};

// Builds one labeled form field for a modal body: `opts` supports
// { id, label, type ('text'|'password'|'email'|'date'|'select'|'static'), value, placeholder,
//   required, optional, icon (an ICON_PATHS key), optionsHtml (for type 'select'), attrs, note,
//   uppercase (visually displays typed text in caps, matching the app's all-caps UI copy) }.
function modalField(opts) {
    opts = opts || {};
    var requiredMark = opts.required ? ' <span class="text-csc-red-600">*</span>' : '';
    var optionalPill = opts.optional
        ? ' <span class="normal-case tracking-normal rounded-full bg-gray-100 dark:bg-white/10 px-2 py-0.5 text-[10px] font-medium text-gray-500 dark:text-gray-400">Optional</span>'
        : '';
    var labelHtml = '<label class="mb-1.5 block text-xs font-bold tracking-wide text-csc-navy-600 dark:text-csc-navy-300" for="' + opts.id + '">'
        + escapeHtml(opts.label || '') + requiredMark + optionalPill + '</label>';

    if (opts.type === 'static') {
        return '<div>' + labelHtml + '<p class="text-sm text-gray-900 dark:text-white">' + escapeHtml(opts.value || '') + '</p>'
            + (opts.note ? '<p class="mt-1 text-xs text-gray-500 dark:text-gray-500">' + escapeHtml(opts.note) + '</p>' : '') + '</div>';
    }

    var iconHtml = opts.icon
        ? '<span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-gray-400 dark:text-gray-500">' + modalFieldIcon(opts.icon) + '</span>'
        : '';
    var controlClass = 'w-full rounded-xl bg-gray-50 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2.5 text-sm placeholder:text-gray-400 dark:placeholder:text-gray-500 focus:border-csc-navy-500 focus:ring-2 focus:ring-csc-navy-500/20 focus:outline-none disabled:opacity-60'
        + (opts.icon ? ' pl-10' : '')
        + (opts.uppercase ? ' uppercase placeholder:normal-case dark:placeholder:normal-case' : '');

    var controlHtml;
    if (opts.type === 'select') {
        controlHtml = '<select id="' + opts.id + '" class="' + controlClass + '"' + (opts.attrs || '') + '>' + (opts.optionsHtml || '') + '</select>';
    } else {
        controlHtml = '<input id="' + opts.id + '" type="' + (opts.type || 'text') + '"'
            + (opts.value !== undefined ? ' value="' + escapeHtml(opts.value) + '"' : '')
            + (opts.placeholder ? ' placeholder="' + escapeHtml(opts.placeholder) + '"' : '')
            + ' class="' + controlClass + '"' + (opts.attrs || '') + '>';
    }

    var noteHtml = opts.note ? '<p class="mt-1 text-xs text-gray-500 dark:text-gray-500">' + escapeHtml(opts.note) + '</p>' : '';

    return '<div>' + labelHtml + '<div class="relative">' + iconHtml + controlHtml + '</div>' + noteHtml + '</div>';
}

// Hand-rolled SVG line-trend chart for admin/dashboard.php's TREND card
// (#filing-trend-chart, #filing-trend-note). Call once the markup exists in
// the DOM, with {initialTrendMonthly, initialTo} — a no-op if the chart
// container isn't on the page. Takes an options.series list of {key,
// textClass, dotClass, gradOpacity, label} describing which fields to plot
// (dashboard passes on_record/processing/approved); TREND_DEFAULT_SERIES
// below is the fallback when no options.series is given.
var TREND_DEFAULT_SERIES = [
    { key: 'on_record', textClass: 'text-csc-navy-600 dark:text-csc-navy-300', dotClass: 'bg-csc-navy-300', gradOpacity: '0.28', label: 'GRADUATED' },
    { key: 'applied', textClass: 'text-emerald-500', dotClass: 'bg-emerald-400', gradOpacity: '0.22', label: 'APPLIED FOR HGE' },
];

function initTrendChart(config, options) {
    var trendContainer = document.getElementById('filing-trend-chart');
    if (!trendContainer) {
        return;
    }
    options = options || {};
    var seriesList = options.series || TREND_DEFAULT_SERIES;
    var trendSeries = config.initialTrend || [];
    var trendMonthly = config.initialTrendMonthly || { months: [], undated: { on_record: 0 } };
    // admin/dashboard.php's config sets initialMode: 'month' and the chart
    // always follows its year slicer instead of a PER YEAR/PER MONTH toggle
    // (there is no [data-trend-mode] button on that page, so the click
    // handler registered below is simply inert there).
    var trendMode = config.initialMode === 'month' ? 'month' : 'year';
    var trendSelectedYear = config.initialTo;
    // Which point(s) get the "current" highlight (bold label, bigger dot).
    // Default: every point belonging to the selected year. admin/dashboard.php's
    // chart only ever shows one year's months, where that would bold every
    // point, so it supplies its own comparator (highlight just the latest
    // month) via options.isSelectedPoint.
    var isSelectedPoint = typeof options.isSelectedPoint === 'function'
        ? options.isSelectedPoint
        : function (p) { return p.year === trendSelectedYear; };
    var trendNote = document.getElementById('filing-trend-note');
    var TREND_MONTH_ABBR = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];
    var TREND_MONTH_NAMES = ['JANUARY', 'FEBRUARY', 'MARCH', 'APRIL', 'MAY', 'JUNE', 'JULY', 'AUGUST', 'SEPTEMBER', 'OCTOBER', 'NOVEMBER', 'DECEMBER'];

    // The points currently charted, whichever grouping is selected. "label" is
    // the x-axis text, "title" the tooltip heading, "year" what the selected-
    // year highlight compares against — every other field on the raw point
    // (on_record/applied, or on_record/processing/approved) passes through
    // untouched so seriesList's `key`s can read whichever of them it needs.
    function trendPoints() {
        if (trendMode === 'month') {
            var multiYear = trendMonthly.months.length > 12;
            return trendMonthly.months.map(function (p) {
                var point = {
                    year: p.year,
                    label: TREND_MONTH_ABBR[p.month - 1] + (multiYear ? " '" + String(p.year).slice(-2) : ''),
                    title: TREND_MONTH_NAMES[p.month - 1] + ' ' + p.year,
                };
                Object.keys(p).forEach(function (key) {
                    if (key !== 'year' && key !== 'month') {
                        point[key] = p[key];
                    }
                });
                return point;
            });
        }
        return trendSeries.map(function (p) {
            var point = { year: p.year, label: String(p.year), title: String(p.year) };
            Object.keys(p).forEach(function (key) {
                if (key !== 'year') {
                    point[key] = p[key];
                }
            });
            return point;
        });
    }

    // Per month, a filed-date/approved-date series counts by when that event
    // actually happened, not by when the applicant graduated — say so under
    // the chart. options.monthNote lets admin/dashboard.php's 3-series chart
    // supply its own wording instead of this 2-series default.
    function updateTrendNote() {
        var legend = document.getElementById('filing-trend-applied-label');
        if (legend) {
            legend.textContent = trendMode === 'month' ? 'LATIN HONORS APPLIED FOR HGE (BY MONTH FILED)' : 'LATIN HONORS APPLIED FOR HGE';
        }
        if (!trendNote) {
            return;
        }
        if (trendMode !== 'month') {
            trendNote.classList.add('hidden');
            return;
        }
        var undated = trendMonthly.undated || { on_record: 0 };
        var text = options.monthNote || 'GRADUATED IS PLOTTED BY GRADUATION MONTH; APPLIED IS PLOTTED BY THE MONTH THE APPLICATION WAS ACTUALLY FILED (DATE APPLIED).';
        if (undated.on_record > 0) {
            text += ' ' + undated.on_record.toLocaleString('en-US') + ' GRADUATE' + (undated.on_record === 1 ? '' : 'S') +
                ' HAVE NO GRADUATION DATE ON RECORD AND ARE NOT SHOWN IN THE MONTHS.';
        }
        trendNote.textContent = text;
        trendNote.classList.remove('hidden');
    }

    function niceStep(rawStep) {
        var pow = Math.pow(10, Math.floor(Math.log10(rawStep)));
        var n = rawStep / pow;
        var nice = n <= 1 ? 1 : (n <= 2 ? 2 : (n <= 2.5 ? 2.5 : (n <= 5 ? 5 : 10)));
        return nice * pow;
    }

    function niceAxisMax(value) {
        if (value <= 0) {
            return 4;
        }
        var step = Math.max(niceStep(value / 4), 1);
        return step * 4;
    }

    // Catmull-Rom spline through the points, expressed as cubic Beziers. Each
    // segment's control points are clamped between its two end points' heights
    // (and the plot), so the curve never overshoots a data point — it can't dip
    // below the baseline, and a spike can't push one line over the other.
    function smoothPath(points, minY, maxY) {
        if (points.length < 2) {
            return points.length ? 'M' + points[0].x + ',' + points[0].y : '';
        }
        var d = 'M' + points[0].x.toFixed(1) + ',' + points[0].y.toFixed(1);
        for (var i = 0; i < points.length - 1; i++) {
            var p0 = points[i - 1] || points[i];
            var p1 = points[i];
            var p2 = points[i + 1];
            var p3 = points[i + 2] || p2;
            var segLo = Math.max(minY, Math.min(p1.y, p2.y));
            var segHi = Math.min(maxY, Math.max(p1.y, p2.y));
            var clampY = function (y) {
                return Math.min(segHi, Math.max(segLo, y));
            };
            var c1x = p1.x + (p2.x - p0.x) / 6;
            var c1y = clampY(p1.y + (p2.y - p0.y) / 6);
            var c2x = p2.x - (p3.x - p1.x) / 6;
            var c2y = clampY(p2.y - (p3.y - p1.y) / 6);
            d += ' C' + c1x.toFixed(1) + ',' + c1y.toFixed(1) + ' ' + c2x.toFixed(1) + ',' + c2y.toFixed(1) + ' ' + p2.x.toFixed(1) + ',' + p2.y.toFixed(1);
        }
        return d;
    }

    function renderTrendChart(animate) {
        var series = trendPoints();
        if (!trendContainer || series.length === 0) {
            return;
        }
        var width = trendContainer.clientWidth;
        var height = trendContainer.clientHeight;
        if (width < 120) {
            return;
        }

        var m = { top: 14, right: 16, bottom: 28, left: 48 };
        var plotW = width - m.left - m.right;
        var plotH = height - m.top - m.bottom;
        var inset = 24;
        // All series share one axis — a subset series (applied/processing/
        // approved are each subsets of on_record) must never be drawn above
        // the population it's drawn from, which a separate per-series scale
        // would allow.
        var maxValue = niceAxisMax(Math.max.apply(null, series.map(function (p) {
            return Math.max.apply(null, seriesList.map(function (s) { return p[s.key] || 0; }));
        })));
        var baseY = m.top + plotH;

        function xAt(i) {
            return m.left + inset + (series.length === 1 ? (plotW - 2 * inset) / 2 : i * (plotW - 2 * inset) / (series.length - 1));
        }
        function yAt(value) {
            return baseY - (value / maxValue) * plotH;
        }

        // One { x, y } point array + smoothed path per series, in seriesList order.
        var seriesPoints = seriesList.map(function (s) {
            return series.map(function (p, i) { return { x: xAt(i), y: yAt(p[s.key] || 0) }; });
        });
        var seriesLines = seriesPoints.map(function (points) { return smoothPath(points, m.top, baseY); });
        var lastX = seriesPoints[0][seriesPoints[0].length - 1].x;
        var firstX = seriesPoints[0][0].x;

        var svg = '<svg width="' + width + '" height="' + height + '" viewBox="0 0 ' + width + ' ' + height + '" class="block overflow-visible">';
        svg += '<defs>' + seriesList.map(function (s, i) {
            return '<linearGradient id="trend-grad-' + i + '" x1="0" y1="0" x2="0" y2="1" class="' + s.textClass + '"><stop offset="0%" stop-color="currentColor" stop-opacity="' + s.gradOpacity + '"/><stop offset="100%" stop-color="currentColor" stop-opacity="0"/></linearGradient>';
        }).join('') + '</defs>';

        for (var t = 0; t <= 4; t++) {
            var gy = m.top + (plotH * t / 4);
            var axisVal = Math.round(maxValue * (4 - t) / 4);
            svg += '<line x1="' + m.left + '" y1="' + gy + '" x2="' + (width - m.right) + '" y2="' + gy + '" stroke="currentColor" stroke-width="1" class="text-gray-200 dark:text-white/10"/>';
            svg += '<text x="' + (m.left - 8) + '" y="' + (gy + 4) + '" text-anchor="end" font-size="11" fill="currentColor" class="text-gray-500 dark:text-gray-400">' + axisVal.toLocaleString('en-US') + '</text>';
        }

        // Many points (months across several years) can't all carry a label:
        // show every step-th one so neighbouring labels never overlap.
        var spacing = series.length > 1 ? (plotW - 2 * inset) / (series.length - 1) : plotW;
        var labelStep = Math.max(1, Math.ceil(46 / spacing));
        series.forEach(function (p, i) {
            if (i % labelStep !== 0) {
                return;
            }
            var isSelected = isSelectedPoint(p, i, series);
            svg += '<text x="' + xAt(i) + '" y="' + (height - 8) + '" text-anchor="middle" font-size="11" fill="currentColor" class="' +
                (isSelected ? 'text-csc-navy-600 dark:text-white font-bold' : 'text-gray-500 dark:text-gray-400') + '">' + p.label + '</text>';
        });

        seriesList.forEach(function (s, i) {
            var line = seriesLines[i];
            svg += '<g class="' + s.textClass + '"><path data-trend-area d="' + line + ' L' + lastX.toFixed(1) + ',' + baseY + ' L' + firstX.toFixed(1) + ',' + baseY + ' Z" fill="url(#trend-grad-' + i + ')" stroke="none"/>' +
                '<path data-trend-line d="' + line + '" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"/></g>';
        });

        var dense = spacing < 14;
        series.forEach(function (p, i) {
            var r = dense ? 1.75 : (isSelectedPoint(p, i, series) && trendMode === 'year' ? 4.5 : 3);
            seriesList.forEach(function (s, si) {
                var pt = seriesPoints[si][i];
                svg += '<circle cx="' + pt.x + '" cy="' + pt.y + '" r="' + r + '" fill="currentColor" stroke="white" stroke-width="1.5" class="' + s.textClass + '"/>';
            });
        });

        svg += '<line id="trend-hover-line" x1="0" y1="' + m.top + '" x2="0" y2="' + baseY + '" stroke="currentColor" stroke-width="1" stroke-dasharray="4 4" class="text-csc-navy-600 dark:text-csc-navy-300" style="display:none"/>';
        seriesList.forEach(function (s, i) {
            svg += '<circle id="trend-hover-' + i + '" r="6" fill="white" stroke="currentColor" stroke-width="2.5" class="' + s.textClass + '" style="display:none"/>';
        });
        svg += '<rect id="trend-hit" x="' + m.left + '" y="' + m.top + '" width="' + plotW + '" height="' + plotH + '" fill="transparent"/>';
        svg += '</svg>';
        svg += '<div id="trend-tooltip" class="pointer-events-none absolute z-10 hidden rounded-lg bg-gray-900 px-2.5 py-1.5 text-xs text-white shadow-lg whitespace-nowrap dark:bg-white dark:text-gray-900"></div>';

        trendContainer.innerHTML = svg;

        var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (animate && !reduceMotion) {
            trendContainer.querySelectorAll('[data-trend-line]').forEach(function (path) {
                var len = path.getTotalLength();
                path.style.strokeDasharray = len;
                path.style.strokeDashoffset = len;
                path.getBoundingClientRect();
                path.style.transition = 'stroke-dashoffset 1200ms ease-out';
                path.style.strokeDashoffset = 0;
            });
            trendContainer.querySelectorAll('[data-trend-area]').forEach(function (path) {
                path.style.opacity = 0;
                path.getBoundingClientRect();
                path.style.transition = 'opacity 1200ms ease-out';
                path.style.opacity = 1;
            });
        }

        var hit = document.getElementById('trend-hit');
        var hoverLine = document.getElementById('trend-hover-line');
        var hoverDots = seriesList.map(function (s, i) { return document.getElementById('trend-hover-' + i); });
        var tooltip = document.getElementById('trend-tooltip');

        function hideHover() {
            hoverLine.style.display = 'none';
            hoverDots.forEach(function (dot) { dot.style.display = 'none'; });
            tooltip.classList.add('hidden');
        }

        function showHover(event) {
            var rect = trendContainer.getBoundingClientRect();
            var px = event.clientX - rect.left;
            var nearest = 0;
            var best = Infinity;
            series.forEach(function (p, i) {
                var dist = Math.abs(xAt(i) - px);
                if (dist < best) {
                    best = dist;
                    nearest = i;
                }
            });
            var point = series[nearest];
            var x = xAt(nearest);

            hoverLine.setAttribute('x1', x);
            hoverLine.setAttribute('x2', x);
            hoverLine.style.display = '';

            var tooltipRows = '';
            var minY = Infinity;
            seriesList.forEach(function (s, si) {
                var pt = seriesPoints[si][nearest];
                hoverDots[si].setAttribute('cx', x);
                hoverDots[si].setAttribute('cy', pt.y);
                hoverDots[si].style.display = '';
                minY = Math.min(minY, pt.y);
                tooltipRows += '<div class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full ' + s.dotClass + '"></span>' + s.label + ': <b>' + (point[s.key] || 0).toLocaleString('en-US') + '</b></div>';
            });

            tooltip.innerHTML = '<div class="font-semibold mb-0.5">' + point.title + '</div>' + tooltipRows;
            tooltip.classList.remove('hidden');
            var tipW = tooltip.offsetWidth;
            var left = x + 14;
            if (left + tipW > width - 4) {
                left = x - tipW - 14;
            }
            tooltip.style.left = Math.max(4, left) + 'px';
            tooltip.style.top = Math.max(0, minY - 20) + 'px';
        }

        hit.addEventListener('pointermove', showHover);
        hit.addEventListener('pointerdown', showHover);
        hit.addEventListener('pointerleave', hideHover);
    }

    document.querySelectorAll('[data-trend-mode]').forEach(function (button) {
        button.addEventListener('click', function () {
            trendMode = button.getAttribute('data-trend-mode') === 'month' ? 'month' : 'year';
            document.querySelectorAll('[data-trend-mode]').forEach(function (other) {
                other.setAttribute('aria-pressed', other === button ? 'true' : 'false');
            });
            updateTrendNote();
            renderTrendChart(true);
        });
    });

    updateTrendNote();
    renderTrendChart(true);

    var trendResizeFrame = null;
    var trendLastWidth = trendContainer.clientWidth;
    new ResizeObserver(function () {
        if (trendContainer.clientWidth === trendLastWidth) {
            return;
        }
        trendLastWidth = trendContainer.clientWidth;
        cancelAnimationFrame(trendResizeFrame);
        trendResizeFrame = requestAnimationFrame(function () { renderTrendChart(false); });
    }).observe(trendContainer);

    // Lets the page swap in fresh data after its year slicer changes without
    // re-running the whole setup — newTrendMonthly is optional, so a caller
    // that only has a new selected year can still refresh the highlight.
    return {
        update: function (newTrend, newTrendMonthly, newSelectedYear) {
            trendSeries = newTrend || [];
            if (newTrendMonthly) {
                trendMonthly = newTrendMonthly;
            }
            trendSelectedYear = newSelectedYear;
            updateTrendNote();
            renderTrendChart(true);
        }
    };
}

// Tweens each Latin Honor tier's count/percent/bar to a new total — used by
// admin/dashboard.php's merged card via initFilingDonut() below (ids
// "dashboard-tier-*"). tierEls maps each honor name to {count, percent, bar}
// elements (any may be null/absent if not all are rendered).
function updateTierBars(tierEls, breakdown, totalOnRecord) {
    breakdown = breakdown || {};
    Object.keys(tierEls).forEach(function (tierName) {
        var els = tierEls[tierName];
        var count = breakdown[tierName] || 0;
        var percent = totalOnRecord > 0 ? Math.round((count / totalOnRecord) * 1000) / 10 : 0;
        if (els.count) {
            var previousCount = parseInt(String(els.count.textContent).replace(/,/g, ''), 10) || 0;
            animateCountUp(els.count, count, 800, null, previousCount);
        }
        if (els.percent) {
            els.percent.textContent = '(' + percent + '%)';
        }
        if (els.bar) {
            els.bar.style.width = percent + '%';
        }
    });
}

// APPLICATION FILING OVERVIEW donut for admin/dashboard.php (single-year,
// slicer-driven). The LATIN HONOR DISTRIBUTION tier bars live inside this
// same card (merged with the year-selected donut), so update() also
// refreshes them when a tierBreakdown is passed in.
function initFilingDonut() {
    var donutAppliedArc = document.getElementById('filing-donut-applied-arc');
    // The outer-ring PROCESSING/APPROVED arcs are optional (null if the
    // markup doesn't render them), and the radius used for the circumference
    // math is always read off each element itself rather than assumed.
    var donutProcessingArc = document.getElementById('filing-donut-processing-arc');
    var donutApprovedArc = document.getElementById('filing-donut-approved-arc');
    var innerCircumference = donutAppliedArc ? 2 * Math.PI * parseFloat(donutAppliedArc.getAttribute('r')) : 0;
    var outerCircumference = donutProcessingArc ? 2 * Math.PI * parseFloat(donutProcessingArc.getAttribute('r')) : 0;

    var filingRate = document.getElementById('filing-rate');
    var legendEls = {
        onRecord: { count: document.getElementById('filing-onrecord-count'), percent: document.getElementById('filing-onrecord-percent') },
        applied: { count: document.getElementById('filing-applied-count'), percent: document.getElementById('filing-applied-percent') },
        processing: { count: document.getElementById('filing-processing-count'), percent: document.getElementById('filing-processing-percent') },
        approved: { count: document.getElementById('filing-approved-count'), percent: document.getElementById('filing-approved-percent') },
        notYetApplied: { count: document.getElementById('filing-notyetapplied-count'), percent: document.getElementById('filing-notyetapplied-percent') },
    };
    // "(N filed in <year>)" annotations next to APPLIED/PROCESSING/APPROVED —
    // the main legend counts are scoped by the applicant's own graduation
    // year (matching the ON RECORD ring they're divided into), while this
    // subnote names how many of that total were actually FILED within the
    // selected calendar year, regardless of the applicant's graduation year
    // (see includes/reports.php: get_application_filing_report() /
    // get_applied_breakdown_by_filed_year()).
    var subnoteEls = {
        applied: document.getElementById('filing-applied-subnote'),
        processing: document.getElementById('filing-processing-subnote'),
        approved: document.getElementById('filing-approved-subnote'),
    };

    var tierEls = {
        'CUM LAUDE': {
            count: document.getElementById('dashboard-tier-cum-laude'),
            percent: document.getElementById('dashboard-tier-cum-laude-percent'),
            bar: document.getElementById('dashboard-tier-cum-laude-bar'),
        },
        'MAGNA CUM LAUDE': {
            count: document.getElementById('dashboard-tier-magna-cum-laude'),
            percent: document.getElementById('dashboard-tier-magna-cum-laude-percent'),
            bar: document.getElementById('dashboard-tier-magna-cum-laude-bar'),
        },
        'SUMMA CUM LAUDE': {
            count: document.getElementById('dashboard-tier-summa-cum-laude'),
            percent: document.getElementById('dashboard-tier-summa-cum-laude-percent'),
            bar: document.getElementById('dashboard-tier-summa-cum-laude-bar'),
        },
    };

    // One-time entrance reveal: PHP renders every arc fully hidden (the inner
    // ring via stroke-dashoffset = its own full circumference; the outer
    // ring's two arcs via a zero-length stroke-dasharray) so the first paint
    // is the "before" state, then this sets each one's real target a tick
    // later so the motion-safe:transition-all class already on the element
    // animates the draw-in.
    requestAnimationFrame(function () {
        requestAnimationFrame(function () {
            if (donutAppliedArc) {
                donutAppliedArc.setAttribute('stroke-dashoffset', donutAppliedArc.getAttribute('data-target-offset'));
            }
            [donutProcessingArc, donutApprovedArc].forEach(function (arc) {
                if (arc) {
                    arc.setAttribute('stroke-dasharray', arc.getAttribute('data-target-dasharray'));
                    arc.setAttribute('stroke-dashoffset', arc.getAttribute('data-target-offset'));
                }
            });
        });
    });

    // animateCountUp() only handles whole numbers, so the one-decimal
    // filing-rate percentage gets its own tween.
    function animateRate(el, target, duration) {
        var from = parseFloat(el.textContent) || 0;
        var token = el.__rateToken = (el.__rateToken || 0) + 1;

        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            el.textContent = target + '%';
            return;
        }

        var startTime = null;
        function step(timestamp) {
            if (el.__rateToken !== token) {
                return;
            }
            if (startTime === null) {
                startTime = timestamp;
            }
            var progress = Math.min((timestamp - startTime) / duration, 1);
            var eased = 1 - Math.pow(1 - progress, 3);
            if (progress < 1) {
                el.textContent = (Math.round((from + (target - from) * eased) * 10) / 10) + '%';
                requestAnimationFrame(step);
            } else {
                el.textContent = target + '%';
            }
        }
        requestAnimationFrame(step);
    }

    return {
        update: function (report, tierBreakdown) {
            var totalOnRecord = report.total_on_record;
            var totalApplied = report.total_applied;
            // Defaults to 0 so the legend/arc math below degrades to
            // "nothing approved yet" instead of producing NaN if a caller
            // ever passes a report shape without total_approved.
            var totalApproved = report.total_approved || 0;
            var totalProcessing = totalApplied - totalApproved;

            if (filingRate) {
                animateRate(filingRate, report.filing_rate, 1000);
            }

            if (donutAppliedArc) {
                var appliedFraction = totalOnRecord > 0 ? totalApplied / totalOnRecord : 0;
                var appliedLength = innerCircumference * appliedFraction;
                donutAppliedArc.setAttribute('stroke-dasharray', innerCircumference.toFixed(2));
                donutAppliedArc.setAttribute('stroke-dashoffset', (innerCircumference - appliedLength).toFixed(2));
            }

            var processingFraction = totalOnRecord > 0 ? totalProcessing / totalOnRecord : 0;
            var approvedFraction = totalOnRecord > 0 ? totalApproved / totalOnRecord : 0;
            if (donutProcessingArc) {
                var processingLength = outerCircumference * processingFraction;
                donutProcessingArc.setAttribute('stroke-dasharray', processingLength.toFixed(2) + ' ' + (outerCircumference - processingLength).toFixed(2));
                donutProcessingArc.setAttribute('stroke-dashoffset', '0');
            }
            if (donutApprovedArc) {
                var approvedLength = outerCircumference * approvedFraction;
                donutApprovedArc.setAttribute('stroke-dasharray', approvedLength.toFixed(2) + ' ' + (outerCircumference - approvedLength).toFixed(2));
                donutApprovedArc.setAttribute('stroke-dashoffset', (-(processingFraction * outerCircumference)).toFixed(2));
            }

            var notYetApplied = totalOnRecord - totalApplied;
            var notYetAppliedPercent = totalOnRecord > 0 ? Math.round((notYetApplied / totalOnRecord) * 1000) / 10 : 0;
            var processingPercent = totalOnRecord > 0 ? Math.round((totalProcessing / totalOnRecord) * 1000) / 10 : 0;
            var approvedPercent = totalOnRecord > 0 ? Math.round((totalApproved / totalOnRecord) * 1000) / 10 : 0;

            // Counts tween from whatever's currently on screen (not 0) — same
            // "this number just changed" treatment as the tier bars above —
            // text is only ever replaced through these ids, so the fixed-width
            // label markup around them (admin/dashboard.php) never gets
            // clobbered by a wholesale innerHTML rewrite.
            [
                { el: legendEls.onRecord, count: totalOnRecord, percent: '100%' },
                { el: legendEls.applied, count: totalApplied, percent: report.filing_rate + '%' },
                { el: legendEls.processing, count: totalProcessing, percent: processingPercent + '%' },
                { el: legendEls.approved, count: totalApproved, percent: approvedPercent + '%' },
                { el: legendEls.notYetApplied, count: notYetApplied, percent: notYetAppliedPercent + '%' },
            ].forEach(function (row) {
                if (row.el.count) {
                    var previousCount = parseInt(String(row.el.count.textContent).replace(/,/g, ''), 10) || 0;
                    animateCountUp(row.el.count, row.count, 800, null, previousCount);
                }
                if (row.el.percent) {
                    row.el.percent.textContent = row.percent;
                }
            });

            if (tierBreakdown) {
                updateTierBars(tierEls, tierBreakdown, totalOnRecord);
            }

            if (report.year) {
                var filedYearApplied = report.filed_year_applied || 0;
                var filedYearApproved = report.filed_year_approved || 0;
                var filedYearProcessing = filedYearApplied - filedYearApproved;
                var otherApplied = report.filed_year_other_applied || 0;
                var otherApproved = report.filed_year_other_approved || 0;
                // The note counts every application filed that year, including graduates of other
                // years, so say how many of them those are (admin/dashboard.php renders the same text).
                var filedNote = function (total, other) {
                    return '(' + formatCount(total) + ' filed in ' + report.year
                        + (other > 0 ? ', ' + formatCount(other) + ' from other graduation years' : '') + ')';
                };
                if (subnoteEls.applied) {
                    subnoteEls.applied.textContent = filedNote(filedYearApplied, otherApplied);
                }
                if (subnoteEls.processing) {
                    subnoteEls.processing.textContent = filedNote(filedYearProcessing, otherApplied - otherApproved);
                }
                if (subnoteEls.approved) {
                    subnoteEls.approved.textContent = filedNote(filedYearApproved, otherApproved);
                }
            }
        }
    };
}
