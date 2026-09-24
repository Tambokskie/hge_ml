// Color/icon per toast type: red for error/caution, yellow for warning,
// green for positive/save confirmations, navy for neutral info.
var TOAST_THEMES = {
    success: {
        icon: 'check',
        card: 'border-green-200 bg-green-50 dark:border-green-500/30 dark:bg-green-500/10',
        iconBg: 'bg-green-600 text-white',
        title: 'text-green-700 dark:text-green-400',
    },
    error: {
        icon: 'warning',
        card: 'border-csc-red-600/20 bg-red-50 dark:border-csc-red-600/30 dark:bg-csc-red-600/10',
        iconBg: 'bg-csc-red-600 text-white',
        title: 'text-csc-red-600 dark:text-csc-red-400',
    },
    warning: {
        icon: 'warning',
        card: 'border-yellow-200 bg-yellow-50 dark:border-yellow-500/30 dark:bg-yellow-500/10',
        iconBg: 'bg-yellow-500 text-white',
        title: 'text-yellow-700 dark:text-yellow-400',
    },
    info: {
        icon: 'info',
        card: 'border-csc-navy-500/20 bg-csc-navy-600/5 dark:border-csc-navy-600/30 dark:bg-csc-navy-600/10',
        iconBg: 'bg-csc-navy-600 text-white',
        title: 'text-csc-navy-600 dark:text-csc-navy-300',
    },
};

// `detail` is an optional second line of lighter, non-colored text below the
// bold colored `message` — for a validation toast like "PLEASE ENTER A VALID
// REASON FOR REMOVAL." / "PLAIN TEXT ONLY — NOT "N/A", "-", ...". Omit it for
// a single-line toast.
function showToast(type, message, detail) {
    var container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'fixed top-4 left-1/2 -translate-x-1/2 z-50 flex flex-col gap-2 w-full max-w-sm px-4';
        document.body.appendChild(container);
    }

    var theme = TOAST_THEMES[type] || TOAST_THEMES.info;

    var toast = document.createElement('div');
    toast.className = 'flex items-start gap-3 rounded-xl border px-4 py-3 shadow-lg ' + theme.card;
    toast.innerHTML =
        '<span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full ' + theme.iconBg + '">' + actionIcon(theme.icon) + '</span>' +
        '<div class="min-w-0 flex-1 pt-0.5">' +
        '<p class="text-sm font-bold ' + theme.title + '">' + escapeHtml(message) + '</p>' +
        (detail ? '<p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">' + escapeHtml(detail) + '</p>' : '') +
        '</div>';
    container.appendChild(toast);

    setTimeout(function () {
        toast.remove();
    }, 5000);
}
