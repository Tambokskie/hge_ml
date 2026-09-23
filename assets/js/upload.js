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

    function extractAndRedirect(uploadId) {
        var extractUrl = form.dataset.extractUrl;
        var installUrl = extractUrl.replace('extract_pdf.php', 'install_ocr_tools.php');
        var csrfToken = form.querySelector('[name="csrf_token"]').value;
        var viewUploadUrl = form.dataset.viewUploadUrl + '?id=' + uploadId;

        showProgress('EXTRACTING RECORDS FROM PDF... THIS CAN TAKE UP TO A MINUTE FOR SCANNED DOCUMENTS.');
        setIndeterminateProgress();

        var formData = new FormData();
        formData.set('csrf_token', csrfToken);
        formData.set('upload_id', uploadId);

        fetch(extractUrl, {
            method: 'POST',
            body: formData,
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (data.status === 'success') {
                    setProgressLabel('DONE. REDIRECTING...');
                    setDeterminateProgress(100);
                    window.location.href = data.redirect_url;
                } else if (data.status === 'ocr_tools_missing') {
                    hideProgress();
                    promptOcrToolsInstall({
                        missing: data.missing,
                        csrfToken: csrfToken,
                        installUrl: installUrl,
                        onInstalling: function () {
                            showProgress('INSTALLING OCR TOOLS... THIS MAY TAKE SEVERAL MINUTES. PLEASE DO NOT CLOSE THIS PAGE.');
                            setIndeterminateProgress();
                        },
                        onInstalled: function () {
                            extractAndRedirect(uploadId);
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
                    hideProgress();
                    showToast('error', data.message);
                    window.location.href = viewUploadUrl;
                }
            })
            .catch(function () {
                hideProgress();
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
});
