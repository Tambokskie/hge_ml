function openPdfDrawer(url, filename) {
    var drawer = document.getElementById('pdf-drawer');
    var overlay = document.getElementById('pdf-drawer-overlay');
    var frame = document.getElementById('pdf-drawer-frame');

    if (!drawer || !overlay || !frame) {
        window.open(url, '_blank');
        return;
    }

    var filenameEl = document.getElementById('pdf-drawer-filename');
    var newTab = document.getElementById('pdf-drawer-new-tab');
    var loading = document.getElementById('pdf-drawer-loading');

    if (newTab) {
        newTab.href = url;
    }
    if (filenameEl) {
        filenameEl.textContent = filename || 'PDF PREVIEW';
    }
    if (loading) {
        loading.classList.remove('hidden');
    }

    frame.src = url;

    overlay.classList.remove('hidden');
    drawer.classList.remove('translate-x-full');
    requestAnimationFrame(function () {
        requestAnimationFrame(function () {
            overlay.classList.remove('opacity-0');
        });
    });

    document.addEventListener('keydown', onPdfDrawerKeydown);
}

function closePdfDrawer() {
    var drawer = document.getElementById('pdf-drawer');
    var overlay = document.getElementById('pdf-drawer-overlay');
    var frame = document.getElementById('pdf-drawer-frame');
    var loading = document.getElementById('pdf-drawer-loading');

    if (!drawer || !overlay || !frame) {
        return;
    }

    drawer.classList.add('translate-x-full');
    overlay.classList.add('opacity-0');
    overlay.addEventListener('transitionend', function hide(event) {
        if (event.target === overlay) {
            overlay.classList.add('hidden');
        }
    }, { once: true });
    frame.src = '';
    if (loading) {
        loading.classList.add('hidden');
    }
    document.removeEventListener('keydown', onPdfDrawerKeydown);
}

function onPdfDrawerKeydown(event) {
    if (event.key === 'Escape') {
        closePdfDrawer();
    }
}

document.addEventListener('DOMContentLoaded', function () {
    var drawer = document.getElementById('pdf-drawer');
    var overlay = document.getElementById('pdf-drawer-overlay');
    var frame = document.getElementById('pdf-drawer-frame');
    var closeBtn = document.getElementById('pdf-drawer-close');

    if (!drawer || !overlay || !frame) {
        return;
    }

    overlay.addEventListener('click', closePdfDrawer);
    if (closeBtn) {
        closeBtn.addEventListener('click', closePdfDrawer);
    }

    frame.addEventListener('load', function () {
        if (!drawer.classList.contains('translate-x-full')) {
            var loading = document.getElementById('pdf-drawer-loading');
            if (loading) {
                loading.classList.add('hidden');
            }
        }
    });
});
