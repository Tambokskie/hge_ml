document.addEventListener('DOMContentLoaded', function () {
    var dropzone = document.getElementById('dropzone');
    var fileInput = document.getElementById('pdf_file');
    var fileInfo = document.getElementById('file-info');
    var form = document.getElementById('upload-form');
    var submitBtn = document.getElementById('upload-submit-btn');
    var statusBox = document.getElementById('upload-status');
    var statusText = document.getElementById('upload-status-text');

    if (!dropzone || !fileInput || !form) {
        return;
    }

    function showStatus(text) {
        if (statusText) {
            statusText.textContent = text;
        }
        if (statusBox) {
            statusBox.classList.remove('hidden');
        }
        if (submitBtn) {
            submitBtn.disabled = true;
        }
    }

    function hideStatus() {
        if (statusBox) {
            statusBox.classList.add('hidden');
        }
        if (submitBtn) {
            submitBtn.disabled = false;
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
        dropzone.classList.add('border-accent-500');
    });

    dropzone.addEventListener('dragleave', function () {
        dropzone.classList.remove('border-accent-500');
    });

    dropzone.addEventListener('drop', function (event) {
        event.preventDefault();
        dropzone.classList.remove('border-accent-500');
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
        var csrfToken = form.querySelector('[name="csrf_token"]').value;
        var viewUploadUrl = form.dataset.viewUploadUrl + '?id=' + uploadId;

        showStatus('EXTRACTING RECORDS FROM PDF... THIS CAN TAKE UP TO A MINUTE FOR SCANNED DOCUMENTS.');

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
                    window.location.href = data.redirect_url;
                } else {
                    hideStatus();
                    showToast('error', data.message);
                    window.location.href = viewUploadUrl;
                }
            })
            .catch(function () {
                hideStatus();
                showToast('error', 'THE FILE WAS UPLOADED BUT COULD NOT BE PROCESSED AUTOMATICALLY. PLEASE EXTRACT IT MANUALLY.');
                window.location.href = viewUploadUrl;
            });
    }

    function submitForm(extraFields) {
        var formData = new FormData(form);
        if (extraFields) {
            Object.keys(extraFields).forEach(function (key) {
                formData.set(key, extraFields[key]);
            });
        }

        showStatus('UPLOADING FILE...');

        fetch(form.action, {
            method: 'POST',
            body: formData,
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (data.status === 'success') {
                    extractAndRedirect(data.upload.id);
                } else if (data.status === 'duplicate') {
                    hideStatus();
                    showModal({
                        title: 'POSSIBLE DUPLICATE UPLOAD',
                        bodyHtml: escapeHtml(data.message) + '<br><br>Existing file: ' + escapeHtml(data.existing_upload.original_filename),
                        buttons: [
                            {
                                label: 'CANCEL',
                                className: 'rounded-lg px-4 py-2 text-sm text-gray-300 hover:bg-white/5',
                            },
                            {
                                label: 'VIEW EXISTING',
                                className: 'rounded-lg px-4 py-2 text-sm text-gray-300 hover:bg-white/5',
                                onClick: function (close) {
                                    close();
                                    window.location.href = form.dataset.viewUploadUrl + '?id=' + data.existing_upload.id;
                                },
                            },
                            {
                                label: 'UPLOAD ANYWAY',
                                className: 'rounded-lg px-4 py-2 text-sm bg-accent-600 hover:bg-accent-500 text-white',
                                onClick: function (close) {
                                    close();
                                    submitForm({ confirm_duplicate: '1' });
                                },
                            },
                        ],
                    });
                } else {
                    hideStatus();
                    showToast('error', data.message);
                }
            })
            .catch(function () {
                hideStatus();
                showToast('error', 'THE UPLOAD COULD NOT BE COMPLETED. PLEASE TRY AGAIN.');
            });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        submitForm(null);
    });
});
