document.addEventListener('DOMContentLoaded', function () {
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
