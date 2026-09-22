function promptOcrToolsInstall(options) {
    showModal({
        title: 'OCR TOOLS REQUIRED',
        bodyHtml: 'THIS PDF HAS NO SELECTABLE TEXT, SO OPTICAL CHARACTER RECOGNITION (OCR) IS NEEDED TO READ IT. '
            + 'THE FOLLOWING REQUIRED TOOL(S) ARE NOT INSTALLED ON THIS SERVER: <strong>' + escapeHtml(options.missing.join(', ')) + '</strong>.'
            + '<br><br>THESE TOOLS ARE REQUIRED FOR THE APPLICATION TO FUNCTION PROPERLY. INSTALL THEM NOW?',
        buttons: [
            {
                label: 'CANCEL',
                className: 'rounded-lg px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5',
                onClick: function (close) {
                    close();
                    if (options.onCancelled) {
                        options.onCancelled();
                    }
                },
            },
            {
                label: 'INSTALL',
                className: 'rounded-lg px-4 py-2 text-sm bg-accent-600 hover:bg-accent-500 text-white',
                onClick: function (close) {
                    close();
                    installOcrTools(options);
                },
            },
        ],
    });
}

function installOcrTools(options) {
    if (options.onInstalling) {
        options.onInstalling();
    }

    var formData = new FormData();
    formData.set('csrf_token', options.csrfToken);

    fetch(options.installUrl, {
        method: 'POST',
        body: formData,
    })
        .then(function (response) {
            return response.json();
        })
        .then(function (data) {
            if (data.status === 'success') {
                if (options.onInstalled) {
                    options.onInstalled();
                }
            } else {
                showToast('error', data.message);
                if (options.onFailed) {
                    options.onFailed();
                }
            }
        })
        .catch(function () {
            showToast('error', 'OCR TOOL INSTALLATION FAILED. PLEASE TRY AGAIN OR INSTALL MANUALLY.');
            if (options.onFailed) {
                options.onFailed();
            }
        });
}
