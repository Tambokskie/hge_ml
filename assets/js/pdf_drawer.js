function pdfDrawerDetailRow(icon, label, value) {
    return '<div class="flex items-start gap-2.5">'
        + '<svg class="h-5 w-5 shrink-0 text-gray-400 dark:text-gray-500 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">' + (ICON_PATHS[icon] || '') + '</svg>'
        + '<div class="min-w-0">'
        + '<p class="text-[11px] font-bold tracking-wide text-gray-500 dark:text-gray-500">' + escapeHtml(label) + '</p>'
        + '<p class="text-sm text-gray-900 dark:text-white break-words">' + escapeHtml(value) + '</p>'
        + '</div></div>';
}

function renderPdfDrawerDetails(filename, details) {
    var fields = document.getElementById('pdf-drawer-details-fields');
    if (!fields) {
        return;
    }

    details = details || {};
    var rows = '';
    rows += pdfDrawerDetailRow('document', 'FILE NAME', filename || 'PDF PREVIEW');
    if (details.school) {
        rows += pdfDrawerDetailRow('building', 'SCHOOL', details.school);
    }
    if (details.schoolYear) {
        rows += pdfDrawerDetailRow('calendar', 'SCHOOL YEAR', details.schoolYear);
    }
    if (details.uploadedAt) {
        rows += pdfDrawerDetailRow('calendar', 'DATE UPLOADED', details.uploadedAt);
    }
    rows += pdfDrawerDetailRow('tag', 'FILE FORMAT', details.fileFormat || 'PDF');

    fields.innerHTML = rows;
}

function setPdfDrawerTab(tab) {
    var tabs = document.querySelectorAll('.pdf-drawer-tab');
    var previewPanel = document.getElementById('pdf-drawer-panel-preview');
    var detailsPanel = document.getElementById('pdf-drawer-panel-details');

    tabs.forEach(function (btn) {
        var isActive = btn.dataset.pdfDrawerTab === tab;
        btn.classList.toggle('border-csc-navy-600', isActive);
        btn.classList.toggle('text-csc-navy-600', isActive);
        btn.classList.toggle('dark:text-csc-navy-300', isActive);
        btn.classList.toggle('border-transparent', !isActive);
        btn.classList.toggle('text-gray-500', !isActive);
        btn.classList.toggle('dark:text-gray-400', !isActive);
    });

    if (previewPanel) {
        previewPanel.classList.toggle('hidden', tab !== 'preview');
    }
    if (detailsPanel) {
        detailsPanel.classList.toggle('hidden', tab !== 'details');
    }
}

function openPdfDrawer(url, filename, details) {
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

    renderPdfDrawerDetails(filename, details);
    setPdfDrawerTab('preview');

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

    document.querySelectorAll('.pdf-drawer-tab').forEach(function (tabBtn) {
        tabBtn.addEventListener('click', function () {
            setPdfDrawerTab(tabBtn.dataset.pdfDrawerTab);
        });
    });

    frame.addEventListener('load', function () {
        if (!drawer.classList.contains('translate-x-full')) {
            var loading = document.getElementById('pdf-drawer-loading');
            if (loading) {
                loading.classList.add('hidden');
            }
        }
    });
});
