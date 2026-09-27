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
        var csrfToken = form.querySelector('[name="csrf_token"]').value;
        var viewUploadUrl = form.dataset.viewUploadUrl + '?id=' + uploadId;

        hideProgress();
        var closeLoadingModal = showLoadingModal('EXTRACTING DATA', 'PLEASE WAIT WHILE WE GATHER THE LATEST INFORMATION.');

        var formData = new FormData();
        formData.set('csrf_token', csrfToken);
        formData.set('upload_id', uploadId);
        if (useOcr) {
            formData.set('use_ocr', '1');
        }

        fetch(extractUrl, {
            method: 'POST',
            body: formData,
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (data.status === 'success') {
                    window.location.href = data.redirect_url;
                } else if (data.status === 'ocr_confirm_required') {
                    closeLoadingModal();
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
                    closeLoadingModal();
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
                    closeLoadingModal();
                    showToast('error', data.message);
                    window.location.href = viewUploadUrl;
                }
            })
            .catch(function () {
                closeLoadingModal();
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
                title: 'POSSIBLE DUPLICATE UPLOAD',
                bodyHtml: escapeHtml(data.message) + '<br><br>Existing file: ' + escapeHtml(data.existing_upload.original_filename),
                buttons: [
                    {
                        label: 'CANCEL',
                        className: 'rounded-lg px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5',
                    },
                    {
                        label: 'VIEW EXISTING',
                        className: 'rounded-lg px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5',
                        onClick: function (close) {
                            close();
                            window.location.href = form.dataset.viewUploadUrl + '?id=' + data.existing_upload.id;
                        },
                    },
                    {
                        label: 'UPLOAD ANYWAY',
                        className: 'rounded-lg px-4 py-2 text-sm bg-csc-navy-600 hover:bg-csc-navy-500 text-white',
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
    if (schoolsSearch && schoolsList) {
        schoolsSearch.addEventListener('input', function () {
            var query = schoolsSearch.value.trim().toUpperCase();
            var visibleCount = 0;
            schoolsList.querySelectorAll('.uploaded-school-item').forEach(function (item) {
                var visible = query === '' || item.dataset.search.indexOf(query) !== -1;
                item.classList.toggle('hidden', !visible);
                if (visible) {
                    visibleCount++;
                }
            });
            if (schoolsEmpty) {
                schoolsEmpty.classList.toggle('hidden', visibleCount !== 0);
            }
        });
    }
});

// UPLOAD DOCUMENT card's PDF / EXCEL TEMPLATE mode switch, the "DOWNLOAD
// TEMPLATE" school-picker modal, and the "UPLOAD FROM TEMPLATE" submit flow.
// Kept in its own DOMContentLoaded listener (separate from the PDF flow
// above) since it is a self-contained feature that a page could in principle
// include without the PDF form.
document.addEventListener('DOMContentLoaded', function () {
    var ACTIVE_MODE_CLASSES = ['bg-white', 'dark:bg-surface-900', 'text-csc-navy-600', 'dark:text-csc-navy-300', 'shadow-sm'];
    var INACTIVE_MODE_CLASSES = ['text-gray-500', 'dark:text-gray-400', 'hover:text-gray-700', 'dark:hover:text-gray-200'];

    var modeButtons = {
        pdf: document.getElementById('upload-mode-pdf-btn'),
        excel: document.getElementById('upload-mode-excel-btn'),
    };
    var modePanels = {
        pdf: document.getElementById('upload-panel-pdf'),
        excel: document.getElementById('upload-panel-excel'),
    };
    var modeDescription = document.getElementById('upload-mode-description');
    var MODE_DESCRIPTIONS = {
        pdf: 'SELECT A SCHOOL, CHOOSE THE SCHOOL YEAR, AND UPLOAD THE LATIN HONOR LIST PDF.',
        excel: 'FILL OUT THE LATIN HONOR TEMPLATE AND UPLOAD IT HERE TO SKIP MANUAL EXTRACTION.',
    };

    function setUploadMode(mode) {
        ['pdf', 'excel'].forEach(function (key) {
            if (!modeButtons[key] || !modePanels[key]) {
                return;
            }
            var isActive = key === mode;
            modePanels[key].classList.toggle('hidden', !isActive);
            modeButtons[key].classList.remove.apply(modeButtons[key].classList, ACTIVE_MODE_CLASSES.concat(INACTIVE_MODE_CLASSES));
            modeButtons[key].classList.add.apply(modeButtons[key].classList, isActive ? ACTIVE_MODE_CLASSES : INACTIVE_MODE_CLASSES);
        });
        if (modeDescription && MODE_DESCRIPTIONS[mode]) {
            modeDescription.textContent = MODE_DESCRIPTIONS[mode];
        }
    }

    if (modeButtons.pdf && modeButtons.excel) {
        modeButtons.pdf.addEventListener('click', function () { setUploadMode('pdf'); });
        modeButtons.excel.addEventListener('click', function () { setUploadMode('excel'); });
    }

    var downloadTemplateBtn = document.getElementById('download-template-btn');
    if (downloadTemplateBtn) {
        downloadTemplateBtn.addEventListener('click', function () {
            var config = window.HGE_UPLOAD || {};
            var schools = config.schools || [];

            if (schools.length === 0) {
                showToast('error', 'ADD A SCHOOL IN SCHOOL MANAGEMENT FIRST BEFORE DOWNLOADING A TEMPLATE.');
                return;
            }

            var optionsHtml = schools.map(function (school) {
                return '<option value="' + school.id + '">' + escapeHtml(school.label) + '</option>';
            }).join('');

            var close = showModal({
                title: 'DOWNLOAD LATIN HONOR TEMPLATE',
                subtitle: 'SELECT THE SCHOOL TO PRE-FILL THE TEMPLATE WITH THE CORRECT NAME AND CAMPUS.',
                icon: modalHeaderIcon('download'),
                bodyHtml: modalField({
                    id: 'template-school-select',
                    label: 'SCHOOL',
                    type: 'select',
                    icon: 'building',
                    required: true,
                    optionsHtml: optionsHtml,
                }),
                buttons: [
                    { label: 'CANCEL', className: MODAL_BTN.secondary },
                    {
                        label: 'DOWNLOAD',
                        className: MODAL_BTN.primary,
                        icon: modalButtonIcon('download'),
                        onClick: function (closeModal) {
                            var select = document.getElementById('template-school-select');
                            var schoolId = select ? select.value : '';
                            if (!schoolId) {
                                return;
                            }
                            window.location.href = config.downloadTemplateUrl + '?school_id=' + encodeURIComponent(schoolId);
                            closeModal();
                        },
                    },
                ],
            });
            void close;
        });
    }

    var excelDropzone = document.getElementById('excel-dropzone');
    var excelFileInput = document.getElementById('excel_file');
    var excelFileInfo = document.getElementById('excel-file-info');
    var templateForm = document.getElementById('template-upload-form');
    var templateSubmitBtn = document.getElementById('template-upload-submit-btn');
    var templateProgressBox = document.getElementById('template-upload-progress');
    var templateProgressLabel = document.getElementById('template-upload-progress-label');
    var templateProgressPercent = document.getElementById('template-upload-progress-percent');
    var templateProgressBar = document.getElementById('template-upload-progress-bar');

    if (!excelDropzone || !excelFileInput || !templateForm) {
        return;
    }

    function showExcelFileInfo(file) {
        if (!file) {
            excelFileInfo.textContent = '';
            return;
        }
        excelFileInfo.textContent = file.name + ' (' + Math.round(file.size / 1024) + ' KB)';
    }

    excelDropzone.addEventListener('click', function () {
        excelFileInput.click();
    });

    excelDropzone.addEventListener('dragover', function (event) {
        event.preventDefault();
        excelDropzone.classList.add('border-csc-navy-500');
    });

    excelDropzone.addEventListener('dragleave', function () {
        excelDropzone.classList.remove('border-csc-navy-500');
    });

    excelDropzone.addEventListener('drop', function (event) {
        event.preventDefault();
        excelDropzone.classList.remove('border-csc-navy-500');
        if (event.dataTransfer.files.length > 0) {
            excelFileInput.files = event.dataTransfer.files;
            showExcelFileInfo(excelFileInput.files[0]);
        }
    });

    excelFileInput.addEventListener('change', function () {
        showExcelFileInfo(excelFileInput.files[0]);
    });

    function showTemplateProgress(label) {
        if (templateProgressLabel) {
            templateProgressLabel.textContent = label;
        }
        if (templateProgressBox) {
            templateProgressBox.classList.remove('hidden');
        }
        if (templateSubmitBtn) {
            templateSubmitBtn.disabled = true;
        }
    }

    function setTemplateDeterminateProgress(percent) {
        if (templateProgressBar) {
            templateProgressBar.style.width = Math.max(0, Math.min(100, percent)) + '%';
        }
        if (templateProgressPercent) {
            templateProgressPercent.textContent = Math.round(percent) + '%';
            templateProgressPercent.classList.remove('hidden');
        }
    }

    function hideTemplateProgress() {
        if (templateProgressBox) {
            templateProgressBox.classList.add('hidden');
        }
        if (templateSubmitBtn) {
            templateSubmitBtn.disabled = false;
        }
        if (templateProgressBar) {
            templateProgressBar.style.width = '0%';
        }
        if (templateProgressPercent) {
            templateProgressPercent.classList.add('hidden');
        }
    }

    function handleTemplateUploadResponse(data) {
        if (data.status === 'success') {
            setTemplateDeterminateProgress(100);
            window.location.href = data.redirect_url;
        } else if (data.status === 'duplicate') {
            hideTemplateProgress();
            showModal({
                title: 'POSSIBLE DUPLICATE UPLOAD',
                bodyHtml: escapeHtml(data.message) + '<br><br>Existing file: ' + escapeHtml(data.existing_upload.original_filename),
                buttons: [
                    {
                        label: 'CANCEL',
                        className: 'rounded-lg px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5',
                    },
                    {
                        label: 'VIEW EXISTING',
                        className: 'rounded-lg px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5',
                        onClick: function (close) {
                            close();
                            window.location.href = templateForm.dataset.viewUploadUrl + '?id=' + data.existing_upload.id;
                        },
                    },
                    {
                        label: 'UPLOAD ANYWAY',
                        className: 'rounded-lg px-4 py-2 text-sm bg-csc-navy-600 hover:bg-csc-navy-500 text-white',
                        onClick: function (close) {
                            close();
                            submitTemplateForm({ confirm_duplicate: '1' });
                        },
                    },
                ],
            });
        } else {
            hideTemplateProgress();
            showToast('error', data.message);
        }
    }

    function submitTemplateForm(extraFields) {
        var formData = new FormData(templateForm);
        if (extraFields) {
            Object.keys(extraFields).forEach(function (key) {
                formData.set(key, extraFields[key]);
            });
        }

        showTemplateProgress('UPLOADING AND READING TEMPLATE...');
        setTemplateDeterminateProgress(0);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', templateForm.action, true);

        xhr.upload.addEventListener('progress', function (event) {
            if (event.lengthComputable) {
                setTemplateDeterminateProgress((event.loaded / event.total) * 100);
            }
        });

        xhr.upload.addEventListener('load', function () {
            setTemplateDeterminateProgress(100);
            showTemplateProgress('VALIDATING TEMPLATE...');
        });

        xhr.addEventListener('load', function () {
            var data = null;
            try {
                data = JSON.parse(xhr.responseText);
            } catch (e) {
                data = null;
            }

            if (!data || xhr.status < 200 || xhr.status >= 300) {
                hideTemplateProgress();
                showToast('error', 'THE UPLOAD COULD NOT BE COMPLETED. PLEASE TRY AGAIN.');
                return;
            }

            handleTemplateUploadResponse(data);
        });

        xhr.addEventListener('error', function () {
            hideTemplateProgress();
            showToast('error', 'THE UPLOAD COULD NOT BE COMPLETED. PLEASE TRY AGAIN.');
        });

        xhr.send(formData);
    }

    templateForm.addEventListener('submit', function (event) {
        event.preventDefault();
        submitTemplateForm(null);
    });
});
