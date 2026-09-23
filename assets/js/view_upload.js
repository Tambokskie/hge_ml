document.addEventListener('DOMContentLoaded', function () {
    var viewPdfBtn = document.getElementById('view-pdf-btn');
    if (viewPdfBtn) {
        viewPdfBtn.addEventListener('click', function () {
            openPdfDrawer(viewPdfBtn.dataset.pdfUrl, viewPdfBtn.dataset.filename);
        });
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

    btn.addEventListener('click', function () {
        var recordsExtracted = parseInt(btn.dataset.recordsExtracted, 10) || 0;
        if (recordsExtracted <= 0) {
            extract();
            return;
        }

        showModal({
            icon: '<svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>',
            iconClassName: 'bg-yellow-500/10 text-yellow-600 dark:text-yellow-400',
            title: 'CONFIRM RE-EXTRACTION',
            bodyHtml: '<p>RE-EXTRACTING WILL DISCARD THE PREVIOUSLY EXTRACTED DATA FOR THIS UPLOAD, INCLUDING ANY EDITS MADE TO IT. THIS CANNOT BE UNDONE. DO YOU WANT TO CONTINUE?</p>',
            buttons: [
                { label: 'CANCEL', className: 'flex-1 rounded-full border border-gray-300 dark:border-white/15 px-4 py-2.5 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 transition-colors' },
                {
                    label: 'RE-EXTRACT',
                    className: 'flex-1 rounded-full px-4 py-2.5 text-sm font-medium bg-csc-navy-600 hover:bg-csc-navy-500 text-white transition-colors',
                    onClick: function (close) {
                        close();
                        extract();
                    },
                },
            ],
        });
    });
});
