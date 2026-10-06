document.addEventListener('DOMContentLoaded', function () {
    var config = window.HGE_VIEW_UPLOAD;

    document.querySelectorAll('.view-pdf-btn').forEach(function (viewPdfBtn) {
        viewPdfBtn.addEventListener('click', function () {
            openPdfDrawer(viewPdfBtn.dataset.pdfUrl, viewPdfBtn.dataset.filename, config && config.pdfDetails);
        });
    });

    var removeBtn = document.getElementById('remove-upload-btn');
    if (config && removeBtn) {
        removeBtn.addEventListener('click', function () {
            showModal({
                icon: '<svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>',
                iconClassName: 'bg-csc-red-600/10 text-csc-red-600 dark:text-csc-red-400',
                title: 'REMOVE THIS FILE?',
                bodyHtml: '<p>THIS WILL PERMANENTLY DELETE THE UPLOAD FOR <strong>' + escapeHtml(removeBtn.dataset.label) + '</strong>. THIS CANNOT BE UNDONE.</p>',
                buttons: [
                    { label: 'CANCEL', className: 'flex-1 rounded-full border border-gray-300 dark:border-white/15 px-4 py-2.5 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 transition-colors' },
                    {
                        label: 'REMOVE FILE',
                        className: 'flex-1 rounded-full px-4 py-2.5 text-sm font-medium bg-csc-red-600 hover:bg-csc-red-500 text-white transition-colors',
                        onClick: function (close) {
                            close();
                            removeBtn.disabled = true;

                            var formData = new FormData();
                            formData.set('csrf_token', config.csrfToken);
                            formData.set('upload_id', removeBtn.dataset.uploadId);

                            fetch(config.apiBase + '/api/delete_upload.php', {
                                method: 'POST',
                                body: formData,
                            })
                                .then(function (response) {
                                    return response.json();
                                })
                                .then(function (data) {
                                    if (data.status === 'success') {
                                        showToast('success', 'UPLOAD REMOVED SUCCESSFULLY.');
                                        window.location.href = config.dashboardUrl;
                                    } else {
                                        removeBtn.disabled = false;
                                        showToast('error', data.message || 'THE UPLOAD COULD NOT BE REMOVED.');
                                    }
                                })
                                .catch(function () {
                                    removeBtn.disabled = false;
                                    showToast('error', 'THE UPLOAD COULD NOT BE REMOVED. PLEASE TRY AGAIN.');
                                });
                        },
                    },
                ],
            });
        });
    }

    var btn = document.getElementById('extract-records-btn');
    var quickReextractBtn = document.getElementById('quick-reextract-btn');
    var continueBtn = document.getElementById('continue-extraction-btn');
    if (!config || (!btn && !continueBtn)) {
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

    function extract(useOcr, isContinuing) {
        var uploadId = isContinuing ? continueBtn.dataset.uploadId : btn.dataset.uploadId;
        setBusy('EXTRACTING...');
        var extractionModal = showExtractionProgressModal('EXTRACTING DATA', 'PLEASE WAIT WHILE WE GATHER THE LATEST INFORMATION.');
        var stopProgressPolling = startExtractionProgressPolling(config.apiBase + '/api/extraction_progress.php', uploadId, extractionModal.update);

        var formData = new FormData();
        formData.set('csrf_token', config.csrfToken);
        formData.set('upload_id', uploadId);
        if (useOcr) {
            formData.set('use_ocr', '1');
        }
        if (isContinuing) {
            formData.set('continue', '1');
        }

        fetch(config.apiBase + '/api/extract_pdf.php', {
            method: 'POST',
            body: formData,
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                stopProgressPolling();
                if (data.status === 'success') {
                    window.location.href = data.redirect_url;
                } else if (data.status === 'ocr_confirm_required') {
                    extractionModal.close();
                    clearBusy();
                    promptOcrExtractionConfirm({
                        message: data.message,
                        onConfirm: function () {
                            extract(true);
                        },
                        onCancelled: clearBusy,
                    });
                } else if (data.status === 'ocr_tools_missing') {
                    extractionModal.close();
                    clearBusy();
                    promptOcrToolsInstall({
                        missing: data.missing,
                        csrfToken: config.csrfToken,
                        installUrl: config.apiBase + '/api/install_ocr_tools.php',
                        onInstalling: function () {
                            setBusy('INSTALLING OCR TOOLS...');
                        },
                        onInstalled: function () {
                            extract(true);
                        },
                        onFailed: clearBusy,
                        onCancelled: clearBusy,
                    });
                } else {
                    extractionModal.close();
                    clearBusy();
                    showToast('error', data.message);
                }
            })
            .catch(function () {
                stopProgressPolling();
                extractionModal.close();
                clearBusy();
                showToast('error', 'EXTRACTION COULD NOT BE STARTED. PLEASE TRY AGAIN.');
            });
    }

    function handleExtractClick(shouldConfirm) {
        if (shouldConfirm !== 'true') {
            extract();
            return;
        }

        showModal({
            icon: modalHeaderIcon('warning'),
            iconClassName: 'bg-yellow-500/10 text-yellow-600 dark:text-yellow-400',
            title: 'CONFIRM RE-EXTRACTION',
            subtitle: 'Re-extracting replaces this upload’s currently staged data.',
            bodyHtml: '<p class="rounded-lg bg-gray-50 dark:bg-white/5 px-3 py-2 text-sm text-gray-700 dark:text-gray-300">THIS WILL DISCARD THE PREVIOUSLY EXTRACTED DATA FOR THIS UPLOAD, INCLUDING ANY EDITS MADE TO IT. THIS CANNOT BE UNDONE.</p>',
            buttons: [
                { label: 'CANCEL', className: MODAL_BTN.secondary },
                {
                    label: 'RE-EXTRACT',
                    icon: modalButtonIcon('reset'),
                    className: MODAL_BTN.primary,
                    onClick: function (close) {
                        close();
                        // Let this modal's own fade-out transition finish
                        // before the loading modal fades in, so the two
                        // never visibly overlap mid-crossfade.
                        setTimeout(extract, 200);
                    },
                },
            ],
        });
    }

    btn.addEventListener('click', function () {
        handleExtractClick(btn.dataset.shouldConfirm);
    });

    if (quickReextractBtn) {
        quickReextractBtn.addEventListener('click', function () {
            handleExtractClick(quickReextractBtn.dataset.shouldConfirm);
        });
    }

    if (continueBtn) {
        continueBtn.addEventListener('click', function () {
            extract(false, true);
        });
    }
});
