document.addEventListener('DOMContentLoaded', function () {
    var dropzone = document.getElementById('dropzone');
    var fileInput = document.getElementById('pdf_file');
    var fileInfo = document.getElementById('file-info');
    var form = document.getElementById('upload-form');
    var submitBtn = document.getElementById('upload-submit-btn');
    var progressBox = document.getElementById('upload-progress');
    var progressLabel = document.getElementById('upload-progress-label');
    var progressPercent = document.getElementById('upload-progress-percent');
    var progressBar = document.getElementById('upload-progress-bar');
    var schoolYearInput = document.getElementById('school_year');

    if (!dropzone || !fileInput || !form) {
        return;
    }

    if (schoolYearInput) {
        schoolYearInput.addEventListener('input', function () {
            schoolYearInput.value = schoolYearInput.value.replace(/\D/g, '');
        });
        schoolYearInput.addEventListener('keydown', function (event) {
            if (event.key.length === 1 && !/[0-9]/.test(event.key) && !event.ctrlKey && !event.metaKey) {
                event.preventDefault();
            }
        });
        schoolYearInput.addEventListener('paste', function (event) {
            var pasted = (event.clipboardData || window.clipboardData).getData('text');
            if (/\D/.test(pasted)) {
                event.preventDefault();
            }
        });
    }

    function showProgress(label) {
        if (progressLabel) {
            progressLabel.textContent = label;
        }
        if (progressBox) {
            progressBox.classList.remove('hidden');
        }
        if (submitBtn) {
            submitBtn.disabled = true;
        }
    }

    function setProgressLabel(label) {
        if (progressLabel) {
            progressLabel.textContent = label;
        }
    }

    function setDeterminateProgress(percent) {
        if (progressBar) {
            progressBar.classList.remove('upload-progress-indeterminate');
            progressBar.style.width = Math.max(0, Math.min(100, percent)) + '%';
        }
        if (progressPercent) {
            progressPercent.textContent = Math.round(percent) + '%';
            progressPercent.classList.remove('hidden');
        }
    }

    function setIndeterminateProgress() {
        if (progressBar) {
            progressBar.style.width = '';
            progressBar.classList.add('upload-progress-indeterminate');
        }
        if (progressPercent) {
            progressPercent.classList.add('hidden');
        }
    }

    function hideProgress() {
        if (progressBox) {
            progressBox.classList.add('hidden');
        }
        if (submitBtn) {
            submitBtn.disabled = false;
        }
        if (progressBar) {
            progressBar.classList.remove('upload-progress-indeterminate');
            progressBar.style.width = '0%';
        }
        if (progressPercent) {
            progressPercent.classList.add('hidden');
        }
    }

    function showFileInfo(file) {
        if (!file) {
            fileInfo.textContent = '';
            return;
        }
        var sizeKb = Math.round(file.size / 1024);
        fileInfo.textContent = file.name + ' (' + sizeKb + ' KB)';
    }

    dropzone.addEventListener('click', function () {
        fileInput.click();
    });

    dropzone.addEventListener('dragover', function (event) {
        event.preventDefault();
        dropzone.classList.add('border-csc-navy-500');
    });

    dropzone.addEventListener('dragleave', function () {
        dropzone.classList.remove('border-csc-navy-500');
    });

    dropzone.addEventListener('drop', function (event) {
        event.preventDefault();
        dropzone.classList.remove('border-csc-navy-500');
        if (event.dataTransfer.files.length > 0) {
            fileInput.files = event.dataTransfer.files;
            showFileInfo(fileInput.files[0]);
        }
    });

    fileInput.addEventListener('change', function () {
        showFileInfo(fileInput.files[0]);
    });

    function extractAndRedirect(uploadId, useOcr) {
        var extractUrl = form.dataset.extractUrl;
        var installUrl = extractUrl.replace('extract_pdf.php', 'install_ocr_tools.php');
        var progressUrl = extractUrl.replace('extract_pdf.php', 'extraction_progress.php');
        var csrfToken = form.querySelector('[name="csrf_token"]').value;
        var viewUploadUrl = form.dataset.viewUploadUrl + '?id=' + uploadId;

        hideProgress();
        var extractionModal = showExtractionProgressModal('EXTRACTING DATA', 'PLEASE WAIT WHILE WE GATHER THE LATEST INFORMATION.');
        var stopProgressPolling = startExtractionProgressPolling(progressUrl, uploadId, extractionModal.update);

        var fields = { csrf_token: csrfToken, upload_id: uploadId };
        if (useOcr) {
            fields.use_ocr = '1';
        }

        // Every 2-page batch is sent one after the other, each failed batch up to 3 tries.
        runChunkedExtraction({
            url: extractUrl,
            fields: fields,
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
                    promptOcrExtractionConfirm({
                        message: data.message,
                        onConfirm: function () {
                            extractAndRedirect(uploadId, true);
                        },
                        onCancelled: function () {
                            window.location.href = viewUploadUrl;
                        },
                    });
                } else if (data.status === 'ocr_tools_missing') {
                    extractionModal.close();
                    promptOcrToolsInstall({
                        missing: data.missing,
                        csrfToken: csrfToken,
                        installUrl: installUrl,
                        onInstalling: function () {
                            showProgress('INSTALLING OCR TOOLS... THIS MAY TAKE SEVERAL MINUTES. PLEASE DO NOT CLOSE THIS PAGE.');
                            setIndeterminateProgress();
                        },
                        onInstalled: function () {
                            extractAndRedirect(uploadId, true);
                        },
                        onFailed: function () {
                            hideProgress();
                            window.location.href = viewUploadUrl;
                        },
                        onCancelled: function () {
                            window.location.href = viewUploadUrl;
                        },
                    });
                } else {
                    extractionModal.close();
                    showToast('error', data.message);
                    window.location.href = viewUploadUrl;
                }
            })
            .catch(function () {
                stopProgressPolling();
                extractionModal.close();
                showToast('error', 'THE FILE WAS UPLOADED BUT COULD NOT BE PROCESSED AUTOMATICALLY. PLEASE EXTRACT IT MANUALLY.');
                window.location.href = viewUploadUrl;
            });
    }

    function handleUploadResponse(data) {
        if (data.status === 'success') {
            extractAndRedirect(data.upload.id);
        } else if (data.status === 'duplicate') {
            hideProgress();
            showModal({
                icon: modalHeaderIcon('warning'),
                iconClassName: 'bg-yellow-500/10 text-yellow-600 dark:text-yellow-400',
                title: 'THIS SCHOOL YEAR ALREADY HAS A LIST',
                subtitle: 'A LIST WAS ALREADY UPLOADED FOR THIS SCHOOL AND SCHOOL YEAR.',
                bodyHtml: '<p class="rounded-lg bg-gray-50 dark:bg-white/5 px-3 py-2 text-sm text-gray-700 dark:text-gray-300">EXISTING FILE: <span class="font-medium text-gray-900 dark:text-white">' + escapeHtml(data.existing_upload.original_filename) + '</span></p>' +
                    '<p class="mt-3 text-sm text-gray-600 dark:text-gray-400">THE EXISTING LIST WILL NOT BE CHANGED. IF YOU CONTINUE, YOUR NEW FILE IS SAVED AS A SEPARATE UPLOAD, AND ITS FILE NAME IS MARKED AS A DUPLICATE (FOR EXAMPLE, _DUP01).</p>',
                buttons: [
                    { label: 'CANCEL', className: MODAL_BTN.secondary },
                    {
                        label: 'VIEW EXISTING LIST',
                        icon: modalButtonIcon('eye'),
                        className: MODAL_BTN.secondary,
                        onClick: function (close) {
                            close();
                            window.location.href = form.dataset.viewUploadUrl + '?id=' + data.existing_upload.id;
                        },
                    },
                    {
                        label: 'ADD AS SEPARATE UPLOAD',
                        icon: modalButtonIcon('check'),
                        className: MODAL_BTN.primary,
                        onClick: function (close) {
                            close();
                            submitForm({ confirm_duplicate: '1' });
                        },
                    },
                ],
            });
        } else {
            hideProgress();
            showToast('error', data.message);
        }
    }

    function submitForm(extraFields) {
        var formData = new FormData(form);
        if (extraFields) {
            Object.keys(extraFields).forEach(function (key) {
                formData.set(key, extraFields[key]);
            });
        }

        showProgress('UPLOADING FILE...');
        setDeterminateProgress(0);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', form.action, true);

        xhr.upload.addEventListener('progress', function (event) {
            if (event.lengthComputable) {
                setDeterminateProgress((event.loaded / event.total) * 100);
            }
        });

        xhr.upload.addEventListener('load', function () {
            setDeterminateProgress(100);
            setProgressLabel('PROCESSING UPLOAD...');
            setIndeterminateProgress();
        });

        xhr.addEventListener('load', function () {
            var data = null;
            try {
                data = JSON.parse(xhr.responseText);
            } catch (e) {
                data = null;
            }

            if (!data || xhr.status < 200 || xhr.status >= 300) {
                hideProgress();
                showToast('error', 'THE UPLOAD COULD NOT BE COMPLETED. PLEASE TRY AGAIN.');
                return;
            }

            handleUploadResponse(data);
        });

        xhr.addEventListener('error', function () {
            hideProgress();
            showToast('error', 'THE UPLOAD COULD NOT BE COMPLETED. PLEASE TRY AGAIN.');
        });

        xhr.send(formData);
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        submitForm(null);
    });

    var schoolsSearch = document.getElementById('uploaded-schools-search');
    var schoolsList = document.getElementById('uploaded-schools-list');
    var schoolsEmpty = document.getElementById('uploaded-schools-empty');
    var schoolsMore = document.getElementById('uploaded-schools-more');
    if (schoolsSearch && schoolsList) {
        // Only the first few schools are listed by default (the rest carry
        // data-extra="1"); a search looks through every school.
        schoolsSearch.addEventListener('input', function () {
            var query = schoolsSearch.value.trim().toUpperCase();
            var visibleCount = 0;
            schoolsList.querySelectorAll('.uploaded-school-item').forEach(function (item) {
                var visible = query === '' ? item.dataset.extra !== '1' : item.dataset.search.indexOf(query) !== -1;
                item.classList.toggle('hidden', !visible);
                if (visible) {
                    visibleCount++;
                }
            });
            if (schoolsEmpty) {
                schoolsEmpty.classList.toggle('hidden', visibleCount !== 0);
            }
            if (schoolsMore) {
                schoolsMore.classList.toggle('hidden', query !== '');
            }
        });

        // Clicking a school opens its details (read-only) instead of leaving the page.
        schoolsList.addEventListener('click', function (event) {
            var button = event.target.closest('.uploaded-school-open');
            if (button) {
                openSchoolDetailsModal(button.dataset);
            }
        });
    }

    function openSchoolDetailsModal(school) {
        var readOnly = ' readonly tabindex="-1" style="cursor: default"';
        var status = (school.status || '').toUpperCase();
        showModal({
            icon: modalHeaderIcon('building'),
            title: 'SCHOOL DETAILS',
            subtitle: 'Details of this school.',
            bodyHtml: '<div class="space-y-4">' +
                modalField({ id: 'school-detail-name', label: 'SCHOOL NAME', icon: 'building', value: (school.name || '—').toUpperCase(), attrs: readOnly }) +
                modalField({ id: 'school-detail-address', label: 'ADDRESS', icon: 'location', value: (school.address || '—').toUpperCase(), attrs: readOnly }) +
                modalField({ id: 'school-detail-campus', label: 'CAMPUS', optional: true, icon: 'building', value: (school.campus || '—').toUpperCase(), attrs: readOnly }) +
                modalField({ id: 'school-detail-status', label: 'STATUS', icon: 'tag', value: status || '—', attrs: readOnly }) +
                '</div>',
            buttons: [
                { label: 'CLOSE', className: MODAL_BTN.secondary },
            ],
        });
        // showModal() focuses the first field; nothing here is editable, so don't.
        if (document.activeElement && document.activeElement.blur) {
            document.activeElement.blur();
        }
    }
});
