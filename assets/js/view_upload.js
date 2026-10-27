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
                bodyHtml: '<p>THIS WILL MOVE THE UPLOAD FOR <strong>' + escapeHtml(removeBtn.dataset.label) + '</strong> AND ITS FILE TO THE RECYCLE BIN, WHERE IT CAN BE RESTORED.</p>',
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

    var changeCampusBtn = document.getElementById('change-campus-btn');
    if (config && changeCampusBtn) {
        changeCampusBtn.addEventListener('click', function () {
            var campuses = [];
            try { campuses = JSON.parse(changeCampusBtn.dataset.campuses || '[]'); } catch (e) { campuses = []; }
            var records = parseInt(changeCampusBtn.dataset.recordCount, 10) || 0;
            var current = changeCampusBtn.dataset.current;

            if (campuses.length === 0) {
                showModal({
                    icon: modalHeaderIcon('info'),
                    iconClassName: 'bg-csc-navy-600/10 text-csc-navy-600 dark:text-csc-navy-300',
                    title: 'NO OTHER CAMPUS',
                    subtitle: current,
                    bodyHtml: '<p class="rounded-lg bg-gray-50 dark:bg-white/5 px-3 py-2 text-sm text-gray-700 dark:text-gray-300">THIS SCHOOL HAS NO OTHER ACTIVE CAMPUS TO MOVE THE UPLOAD TO. ADD THE CAMPUS IN SCHOOL MANAGEMENT FIRST (SAME SCHOOL NAME, DIFFERENT CAMPUS), THEN COME BACK.</p>',
                    buttons: [
                        { label: 'CLOSE', className: MODAL_BTN.secondary },
                        {
                            label: 'GO TO SCHOOL MANAGEMENT',
                            className: MODAL_BTN.primary,
                            onClick: function () { window.location.href = config.schoolsUrl; },
                        },
                    ],
                });
                return;
            }

            var options = '<option value="">CHOOSE A CAMPUS...</option>' + campuses.map(function (campus) {
                return '<option value="' + campus.id + '">' + escapeHtml(campus.campus === '' ? 'NO CAMPUS NAME' : campus.campus.toUpperCase()) + '</option>';
            }).join('');

            showModal({
                icon: modalHeaderIcon('building'),
                iconClassName: 'bg-csc-navy-600/10 text-csc-navy-600 dark:text-csc-navy-300',
                title: 'CHANGE CAMPUS',
                subtitle: 'Move this upload to another campus of the same school.',
                bodyHtml: '<div class="space-y-4">'
                    + modalField({ id: 'change-campus-current', type: 'static', label: 'CURRENT', value: current })
                    + modalField({ id: 'change-campus-select', type: 'select', label: 'MOVE TO', required: true, icon: 'building', optionsHtml: options })
                    + '<p class="rounded-lg bg-gray-50 dark:bg-white/5 px-3 py-2 text-xs text-gray-600 dark:text-gray-400">'
                    + (records > 0 ? '<strong>' + records + '</strong> SAVED RECORD(S) MOVE WITH THE UPLOAD. ' : '')
                    + 'THE FILE, THE RECORDS’ DATA AND ANY FILED APPLICATIONS STAY EXACTLY AS THEY ARE; ONLY THE CAMPUS CHANGES.</p>'
                    + '</div>',
                buttons: [
                    { label: 'CANCEL', className: MODAL_BTN.secondary },
                    {
                        label: 'CHANGE CAMPUS',
                        icon: modalButtonIcon('check'),
                        className: MODAL_BTN.primary,
                        onClick: function (close) {
                            var select = document.getElementById('change-campus-select');
                            if (!select || select.value === '') {
                                showToast('error', 'CHOOSE THE CAMPUS TO MOVE THIS UPLOAD TO.');
                                return;
                            }
                            close();
                            changeCampusBtn.disabled = true;

                            var formData = new FormData();
                            formData.set('csrf_token', config.csrfToken);
                            formData.set('upload_id', changeCampusBtn.dataset.uploadId);
                            formData.set('school_id', select.value);

                            fetch(config.apiBase + '/api/change_upload_campus.php', { method: 'POST', body: formData })
                                .then(function (response) { return response.json(); })
                                .then(function (data) {
                                    if (data.status === 'success') {
                                        showToast('success', data.message);
                                        setTimeout(function () { window.location.reload(); }, 800);
                                    } else {
                                        changeCampusBtn.disabled = false;
                                        showToast('error', data.message || 'THE CAMPUS COULD NOT BE CHANGED.');
                                    }
                                })
                                .catch(function () {
                                    changeCampusBtn.disabled = false;
                                    showToast('error', 'THE CAMPUS COULD NOT BE CHANGED. PLEASE TRY AGAIN.');
                                });
                        },
                    },
                ],
            });
        });
    }

    // TRANSFER TO ANOTHER SCHOOL: a list filed under the wrong school moves, with its saved records and
    // its file, to any other active school (api/transfer_upload_school.php).
    var transferBtn = document.getElementById('transfer-school-btn');
    if (config && transferBtn) {
        transferBtn.addEventListener('click', function () {
            var schools = [];
            try { schools = JSON.parse(transferBtn.dataset.schools || '[]'); } catch (e) { schools = []; }
            var records = parseInt(transferBtn.dataset.recordCount, 10) || 0;
            var current = transferBtn.dataset.current;
            var year = transferBtn.dataset.year;

            if (transferBtn.dataset.blocked === '1') {
                showModal({
                    icon: modalHeaderIcon('info'),
                    iconClassName: 'bg-csc-navy-600/10 text-csc-navy-600 dark:text-csc-navy-300',
                    title: 'FINISH THE REVIEW FIRST',
                    subtitle: current,
                    bodyHtml: '<p class="rounded-lg bg-gray-50 dark:bg-white/5 px-3 py-2 text-sm text-gray-700 dark:text-gray-300">THIS UPLOAD HAS A SAVED DRAFT REVIEW, WHICH BELONGS TO THE SCHOOL IT WAS STARTED UNDER. RESUME THE REVIEW AND SAVE THE RECORDS, THEN TRANSFER THE UPLOAD.</p>',
                    buttons: [{ label: 'CLOSE', className: MODAL_BTN.secondary }],
                });
                return;
            }

            function optionsFor(filter) {
                var needle = filter.trim().toUpperCase();
                var shown = schools.filter(function (school) {
                    return needle === '' || school.display.toUpperCase().indexOf(needle) !== -1;
                });
                if (shown.length === 0) {
                    return '<option value="" disabled>NO SCHOOL MATCHES</option>';
                }
                return shown.map(function (school) {
                    return '<option value="' + school.id + '">' + escapeHtml(school.display.toUpperCase()) + '</option>';
                }).join('');
            }

            showModal({
                icon: modalHeaderIcon('building'),
                iconClassName: 'bg-csc-navy-600/10 text-csc-navy-600 dark:text-csc-navy-300',
                title: 'TRANSFER TO ANOTHER SCHOOL',
                subtitle: 'Move this upload to the school it really belongs to.',
                bodyHtml: '<div class="space-y-4">'
                    + modalField({ id: 'transfer-school-current', type: 'static', label: 'CURRENT SCHOOL', value: current })
                    + modalField({ id: 'transfer-school-filter', type: 'text', label: 'FIND THE RIGHT SCHOOL', placeholder: 'Type part of the school or campus name', icon: 'search' })
                    + modalField({ id: 'transfer-school-select', type: 'select', label: 'TRANSFER TO', required: true, attrs: ' size="6"', optionsHtml: optionsFor('') })
                    + '<div id="transfer-school-warning" class="hidden rounded-lg border border-amber-300 bg-amber-50 dark:border-amber-500/30 dark:bg-amber-500/10 px-3 py-2 text-xs text-amber-800 dark:text-amber-200"></div>'
                    + '<p class="rounded-lg bg-gray-50 dark:bg-white/5 px-3 py-2 text-xs text-gray-600 dark:text-gray-400">'
                    + (records > 0 ? '<strong>' + records + '</strong> SAVED RECORD(S) MOVE WITH THE UPLOAD AND SHOW THE NEW SCHOOL. ' : '')
                    + 'THE FILE IS RENAMED FOR THE NEW SCHOOL. THE RECORDS’ DATA AND ANY FILED APPLICATIONS STAY EXACTLY AS THEY ARE.</p>'
                    + '</div>',
                buttons: [
                    { label: 'CANCEL', className: MODAL_BTN.secondary },
                    {
                        label: 'TRANSFER',
                        icon: modalButtonIcon('check'),
                        className: MODAL_BTN.primary,
                        onClick: function (close) {
                            var select = document.getElementById('transfer-school-select');
                            if (!select || select.value === '') {
                                showToast('error', 'CHOOSE THE SCHOOL TO TRANSFER THIS UPLOAD TO.');
                                return;
                            }
                            close();
                            transferBtn.disabled = true;

                            var formData = new FormData();
                            formData.set('csrf_token', config.csrfToken);
                            formData.set('upload_id', transferBtn.dataset.uploadId);
                            formData.set('school_id', select.value);

                            fetch(config.apiBase + '/api/transfer_upload_school.php', { method: 'POST', body: formData })
                                .then(function (response) { return response.json(); })
                                .then(function (data) {
                                    if (data.status === 'success') {
                                        showToast('success', data.message);
                                        setTimeout(function () { window.location.reload(); }, 800);
                                    } else {
                                        transferBtn.disabled = false;
                                        showToast('error', data.message || 'THE UPLOAD COULD NOT BE TRANSFERRED.');
                                    }
                                })
                                .catch(function () {
                                    transferBtn.disabled = false;
                                    showToast('error', 'THE UPLOAD COULD NOT BE TRANSFERRED. PLEASE TRY AGAIN.');
                                });
                        },
                    },
                ],
            });

            var filterInput = document.getElementById('transfer-school-filter');
            var schoolSelect = document.getElementById('transfer-school-select');
            var warning = document.getElementById('transfer-school-warning');

            function showWarning() {
                var chosen = schools.filter(function (school) { return String(school.id) === schoolSelect.value; })[0];
                if (!chosen || chosen.same_year_uploads === 0) {
                    warning.classList.add('hidden');
                    warning.textContent = '';
                    return;
                }
                warning.textContent = 'HEADS UP: ' + chosen.display.toUpperCase() + ' ALREADY HAS ' + chosen.same_year_uploads
                    + ' UPLOAD(S) FOR SCHOOL YEAR ' + year + '. THIS FILE WILL BECOME UPLOAD #' + chosen.next_number
                    + ' THERE AND MAY SHOW UP AS A POSSIBLE DUPLICATE. CHECK THAT IT IS NOT THE SAME LIST.';
                warning.classList.remove('hidden');
            }

            if (filterInput && schoolSelect) {
                filterInput.addEventListener('input', function () {
                    schoolSelect.innerHTML = optionsFor(filterInput.value);
                    showWarning();
                });
                schoolSelect.addEventListener('change', showWarning);
                filterInput.focus();
            }
        });
    }
    var btn = document.getElementById('extract-records-btn');
    var quickReextractBtn = document.getElementById('quick-reextract-btn');
    var continueBtn = document.getElementById('continue-extraction-btn');
    var ocrBtn = document.getElementById('extract-ocr-btn');
    if (!config || (!btn && !continueBtn)) {
        return;
    }

    function setBusy(text) {
        if (!btn.dataset.originalText) {
            btn.dataset.originalText = btn.textContent;
        }
        btn.disabled = true;
        btn.textContent = text;
        // Only btn's own text changes (it's the one showing progress), but
        // every sibling action button is disabled too, so a click on one
        // can't race a click on another while a request is in flight.
        if (continueBtn) {
            continueBtn.disabled = true;
        }
        if (ocrBtn) {
            ocrBtn.disabled = true;
        }
    }

    function clearBusy() {
        btn.disabled = false;
        if (btn.dataset.originalText) {
            btn.textContent = btn.dataset.originalText;
        }
        if (continueBtn) {
            continueBtn.disabled = false;
        }
        if (ocrBtn) {
            ocrBtn.disabled = false;
        }
    }

    var errorRow = document.getElementById('extraction-error-row');
    var errorMessageEl = document.getElementById('extraction-error-message');

    // Purely a page-level display, never persisted — a reload or a fresh
    // upload_id clears it along with everything else on the page, by design
    // (the admin asked for this to surface the last attempt's failure
    // without adding any new saved/DB state).
    function showExtractionError(message) {
        if (!errorRow || !errorMessageEl) {
            return;
        }
        errorMessageEl.textContent = message || 'THE EXTRACTION COULD NOT BE COMPLETED.';
        errorRow.classList.remove('hidden');
    }

    function hideExtractionError() {
        if (!errorRow) {
            return;
        }
        errorRow.classList.add('hidden');
    }

    function extract(useOcr, isContinuing) {
        var uploadId = isContinuing ? continueBtn.dataset.uploadId : btn.dataset.uploadId;
        hideExtractionError();
        setBusy('EXTRACTING...');
        var extractionModal = showExtractionProgressModal('EXTRACTING DATA', 'PLEASE WAIT WHILE WE GATHER THE LATEST INFORMATION.');
        var stopProgressPolling = startExtractionProgressPolling(config.apiBase + '/api/extraction_progress.php', uploadId, extractionModal.update);

        var fields = { csrf_token: config.csrfToken, upload_id: uploadId };
        if (!isContinuing && btn.dataset.reextract === '1') {
            // The records of this upload are already saved: a deliberate re-extraction.
            fields.reextract = '1';
        }
        if (useOcr) {
            fields.use_ocr = '1';
        }

        // Every 2-page batch is sent one after the other, each failed batch up to 3 tries.
        runChunkedExtraction({
            url: config.apiBase + '/api/extract_pdf.php',
            fields: fields,
            continuing: !!isContinuing,
            onRetry: function (tryNumber, maxTries) {
                extractionModal.update(0, 'THE AI DID NOT ANSWER. TRYING AGAIN (' + tryNumber + ' OF ' + maxTries + ')...');
            },
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
                    showExtractionError(data.message);
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
                    showExtractionError(data.message);
                    showToast('error', data.message);
                }
            })
            .catch(function () {
                stopProgressPolling();
                extractionModal.close();
                clearBusy();
                showExtractionError('EXTRACTION COULD NOT BE STARTED. PLEASE TRY AGAIN.');
                showToast('error', 'EXTRACTION COULD NOT BE STARTED. PLEASE TRY AGAIN.');
            });
    }

    function isReextraction() {
        return btn.dataset.reextract === '1';
    }

    // What a re-extraction will and will not touch (its numbers come from the page).
    function reextractionBodyHtml() {
        var saved = parseInt(btn.dataset.savedCount, 10) || 0;
        var tagged = parseInt(btn.dataset.taggedCount, 10) || 0;
        var others = Math.max(0, saved - tagged);
        var box = '<p class="rounded-lg bg-gray-50 dark:bg-white/5 px-3 py-2 text-sm text-gray-700 dark:text-gray-300">';
        var html = '<div class="space-y-3">' + box + 'THE FILE IS READ AGAIN AND THE RESULT OPENS AS A NEW REVIEW. <strong>NOTHING IS CHANGED UNTIL YOU CLICK SAVE RECORDS.</strong></p>';
        if (tagged > 0) {
            html += '<p class="rounded-lg border border-green-500/30 bg-green-500/10 px-3 py-2 text-sm text-green-800 dark:text-green-300"><strong>' + tagged +
                '</strong> SAVED RECORD(S) WITH A FILED APPLICATION ARE KEPT EXACTLY AS THEY ARE AND CANNOT BE CHANGED IN THE NEW REVIEW. A NEW ROW WITH THE SAME NAME AS ONE OF THEM IS LEFT OUT.</p>';
        }
        if (others > 0) {
            html += box + 'THE OTHER <strong>' + others + '</strong> SAVED RECORD(S) ARE REPLACED BY THE NEW LIST. ANY OF THEM THAT THE NEW READING DOES NOT INCLUDE IS MOVED TO THE RECYCLE BIN AND CAN BE RESTORED.</p>';
        }

        return html + '</div>';
    }

    function handleExtractClick(shouldConfirm) {
        if (shouldConfirm !== 'true') {
            extract();
            return;
        }

        showModal({
            icon: modalHeaderIcon('warning'),
            iconClassName: 'bg-yellow-500/10 text-yellow-600 dark:text-yellow-400',
            title: isReextraction() ? 'CONFIRM RE-EXTRACTION' : 'CONFIRM AI EXTRACTION',
            subtitle: isReextraction() ? 'Read the file again.' : 'This replaces this upload’s currently staged data.',
            bodyHtml: isReextraction()
                ? reextractionBodyHtml()
                : '<p class="rounded-lg bg-gray-50 dark:bg-white/5 px-3 py-2 text-sm text-gray-700 dark:text-gray-300">THIS WILL DISCARD THE PREVIOUSLY EXTRACTED DATA FOR THIS UPLOAD, INCLUDING ANY EDITS MADE TO IT. THIS CANNOT BE UNDONE.</p>',
            buttons: [
                { label: 'CANCEL', className: MODAL_BTN.secondary },
                {
                    label: isReextraction() ? 'RE-EXTRACT' : 'USE AI EXTRACTION',
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

    if (ocrBtn) {
        ocrBtn.addEventListener('click', function () {
            if (ocrBtn.dataset.shouldConfirm !== 'true') {
                extract(true);
                return;
            }

            showModal({
                icon: modalHeaderIcon('warning'),
                iconClassName: 'bg-yellow-500/10 text-yellow-600 dark:text-yellow-400',
                title: isReextraction() ? 'CONFIRM RE-EXTRACTION VIA TEXT/OCR' : 'CONFIRM TEXT/OCR EXTRACTION',
                subtitle: isReextraction() ? 'Read the file again with text/OCR.' : 'This replaces this upload’s currently staged data.',
                bodyHtml: isReextraction()
                    ? reextractionBodyHtml()
                    : '<p class="rounded-lg bg-gray-50 dark:bg-white/5 px-3 py-2 text-sm text-gray-700 dark:text-gray-300">THIS WILL DISCARD THE PREVIOUSLY EXTRACTED DATA FOR THIS UPLOAD, INCLUDING ANY EDITS MADE TO IT, AND RE-EXTRACT USING TEXT/OCR INSTEAD OF AI. THIS CANNOT BE UNDONE.</p>',
                buttons: [
                    { label: 'CANCEL', className: MODAL_BTN.secondary },
                    {
                        label: isReextraction() ? 'RE-EXTRACT VIA TEXT/OCR' : 'EXTRACT VIA TEXT/OCR',
                        icon: modalButtonIcon('reset'),
                        className: MODAL_BTN.primary,
                        onClick: function (close) {
                            close();
                            // Let this modal's own fade-out transition finish
                            // before the loading modal fades in, so the two
                            // never visibly overlap mid-crossfade.
                            setTimeout(function () {
                                extract(true);
                            }, 200);
                        },
                    },
                ],
            });
        });
    }
});
