function showToast(type, message) {
    var container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'fixed top-4 left-1/2 -translate-x-1/2 z-50 flex flex-col gap-2 w-full max-w-sm px-4';
        document.body.appendChild(container);
    }

    var colors = {
        success: 'border-green-500/30 bg-green-500/10 text-green-400',
        error: 'border-csc-red-600/30 bg-csc-red-600/10 text-csc-red-400',
        warning: 'border-yellow-500/30 bg-yellow-500/10 text-yellow-400',
        info: 'border-csc-navy-600/30 bg-csc-navy-600/10 text-csc-navy-300',
    };
    var colorClass = colors[type] || colors.info;

    var toast = document.createElement('div');
    toast.className = 'rounded-lg border px-4 py-3 text-sm shadow-lg ' + colorClass;
    toast.textContent = message;
    container.appendChild(toast);

    setTimeout(function () {
        toast.remove();
    }, 5000);
}
