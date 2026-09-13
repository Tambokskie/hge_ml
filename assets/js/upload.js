document.addEventListener('DOMContentLoaded', function () {
    var dropzone = document.getElementById('dropzone');
    var fileInput = document.getElementById('pdf_file');
    var fileInfo = document.getElementById('file-info');
    var form = document.getElementById('upload-form');

    if (!dropzone || !fileInput || !form) {
        return;
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

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value;
        return div.innerHTML;
    }

    function submitForm(extraFields) {
        var formData = new FormData(form);
        if (extraFields) {
            Object.keys(extraFields).forEach(function (key) {
                formData.set(key, extraFields[key]);
            });
        }

        fetch(form.action, {
            method: 'POST',
            body: formData,
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (data.status === 'success') {
                    showToast('success', data.message);
                    form.reset();
                    showFileInfo(null);
                } else if (data.status === 'duplicate') {
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
                    showToast('error', data.message);
                }
            })
            .catch(function () {
                showToast('error', 'THE UPLOAD COULD NOT BE COMPLETED. PLEASE TRY AGAIN.');
            });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        submitForm(null);
    });
});
