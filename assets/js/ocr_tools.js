// Shown when AI extraction couldn't process a document and it has no text
// layer either, so (Tesseract) OCR is the only extraction path left — OCR
// is slower and less accurate, so the admin decides before it runs rather
// than it happening silently.
function promptOcrExtractionConfirm(options) {
    showModal({
        icon: '<svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>',
        iconClassName: 'bg-yellow-500/10 text-yellow-600 dark:text-yellow-400',
        title: 'AI EXTRACTION UNAVAILABLE',
        bodyHtml: '<p>' + escapeHtml(options.message) + '</p>',
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
                label: 'USE OCR EXTRACTION',
                className: 'rounded-lg px-4 py-2 text-sm bg-csc-navy-600 hover:bg-csc-navy-500 text-white',
                onClick: function (close) {
                    close();
                    if (options.onConfirm) {
                        options.onConfirm();
                    }
                },
            },
        ],
    });
}

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
                className: 'rounded-lg px-4 py-2 text-sm bg-csc-navy-600 hover:bg-csc-navy-500 text-white',
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
