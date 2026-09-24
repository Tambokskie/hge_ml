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
