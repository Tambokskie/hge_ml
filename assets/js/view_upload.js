document.addEventListener('DOMContentLoaded', function () {
    var viewPdfBtn = document.getElementById('view-pdf-btn');
    var drawer = document.getElementById('pdf-drawer');
    var drawerOverlay = document.getElementById('pdf-drawer-overlay');
    var drawerFrame = document.getElementById('pdf-drawer-frame');
    var drawerFilename = document.getElementById('pdf-drawer-filename');
    var drawerNewTab = document.getElementById('pdf-drawer-new-tab');
    var drawerClose = document.getElementById('pdf-drawer-close');
    var drawerLoading = document.getElementById('pdf-drawer-loading');

    if (viewPdfBtn && drawer && drawerOverlay && drawerFrame) {
        function showLoading() {
            if (drawerLoading) {
                drawerLoading.classList.remove('hidden');
            }
        }

        function hideLoading() {
            if (drawerLoading) {
                drawerLoading.classList.add('hidden');
            }
        }

        function openPdfDrawer() {
            var pdfUrl = viewPdfBtn.dataset.pdfUrl;
            var filename = viewPdfBtn.dataset.filename || 'PDF PREVIEW';

            if (drawerNewTab) {
                drawerNewTab.href = pdfUrl;
            }
            if (drawerFilename) {
                drawerFilename.textContent = filename;
            }

            showLoading();
            drawerFrame.src = pdfUrl;

            drawerOverlay.classList.remove('hidden');
            drawer.classList.remove('translate-x-full');
            requestAnimationFrame(function () {
                requestAnimationFrame(function () {
                    drawerOverlay.classList.remove('opacity-0');
                });
            });

            document.addEventListener('keydown', onKeydown);
        }

        function closePdfDrawer() {
            drawer.classList.add('translate-x-full');
            drawerOverlay.classList.add('opacity-0');
            drawerOverlay.addEventListener('transitionend', function hide(event) {
                if (event.target === drawerOverlay) {
                    drawerOverlay.classList.add('hidden');
                }
            }, { once: true });
            drawerFrame.src = '';
            hideLoading();
            document.removeEventListener('keydown', onKeydown);
        }

        function onKeydown(event) {
            if (event.key === 'Escape') {
                closePdfDrawer();
            }
        }

        drawerFrame.addEventListener('load', function () {
            if (!drawer.classList.contains('translate-x-full')) {
                hideLoading();
            }
        });

        viewPdfBtn.addEventListener('click', openPdfDrawer);
        drawerOverlay.addEventListener('click', closePdfDrawer);
        if (drawerClose) {
            drawerClose.addEventListener('click', closePdfDrawer);
        }
    }

    var config = window.HGE_VIEW_UPLOAD;
    var btn = document.getElementById('extract-records-btn');
    if (!config || !btn) {
        return;
    }

    function setBusy(text) {
        if (!btn.dataset.originalText) {
            btn.dataset.originalText = btn.textContent;
        }
        btn.disabled = true;
        btn.textContent = text;
    }

    function clearBusy() {
        btn.disabled = false;
        if (btn.dataset.originalText) {
            btn.textContent = btn.dataset.originalText;
        }
    }

    function extract() {
        setBusy('EXTRACTING...');

        var formData = new FormData();
        formData.set('csrf_token', config.csrfToken);
        formData.set('upload_id', btn.dataset.uploadId);

        fetch(config.apiBase + '/api/extract_pdf.php', {
            method: 'POST',
            body: formData,
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (data.status === 'success') {
                    window.location.href = data.redirect_url;
                } else if (data.status === 'ocr_tools_missing') {
                    clearBusy();
                    promptOcrToolsInstall({
                        missing: data.missing,
                        csrfToken: config.csrfToken,
                        installUrl: config.apiBase + '/api/install_ocr_tools.php',
                        onInstalling: function () {
                            setBusy('INSTALLING OCR TOOLS...');
                        },
                        onInstalled: extract,
                        onFailed: clearBusy,
                        onCancelled: clearBusy,
                    });
                } else {
                    clearBusy();
                    showToast('error', data.message);
                }
            })
            .catch(function () {
                clearBusy();
                showToast('error', 'EXTRACTION COULD NOT BE STARTED. PLEASE TRY AGAIN.');
            });
    }

    btn.addEventListener('click', extract);
});
