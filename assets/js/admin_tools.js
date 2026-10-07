document.addEventListener('DOMContentLoaded', function () {
    var config = window.HGE_ADMIN;
    if (!config) {
        return;
    }

    function postJson(url, data) {
        var formData = new FormData();
        Object.keys(data).forEach(function (key) {
            formData.set(key, data[key]);
        });
        return fetch(url, { method: 'POST', body: formData }).then(function (r) {
            return r.json();
        });
    }

    function reload() {
        window.location.reload();
    }
    function openBackupModal() {
        var submitting = false;
        var bodyHtml =
            '<div class="space-y-4">' +
            modalField({ id: 'modal-backup-username', label: 'USERNAME', required: true, icon: 'user' }) +
            modalField({ id: 'modal-backup-password', label: 'PASSWORD', required: true, type: 'password', icon: 'lock' }) +
            '</div>';

        showModal({
            icon: modalHeaderIcon('lock'),
            title: 'CONFIRM ADMINISTRATOR IDENTITY',
            subtitle: 'For security, re-enter your credentials to download a full database backup.',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: MODAL_BTN.secondary },
                {
                    label: 'CREATE DB BACKUP',
                    icon: modalButtonIcon('download'),
                    className: MODAL_BTN.primary,
                    onClick: function (modalClose) {
                        if (submitting) {
                            return;
                        }
                        submitting = true;

                        var formData = new FormData();
                        formData.set('csrf_token', config.csrfToken);
                        formData.set('username', document.getElementById('modal-backup-username').value);
                        formData.set('password', document.getElementById('modal-backup-password').value);

                        fetch(config.apiBase + '/api/backup_database.php', { method: 'POST', body: formData })
                            .then(function (response) {
                                var contentType = response.headers.get('Content-Type') || '';
                                if (contentType.indexOf('application/json') !== -1) {
                                    return response.json().then(function (data) {
                                        throw new Error(data.message || 'THE BACKUP COULD NOT BE CREATED.');
                                    });
                                }

                                var filename = 'hge_masterlist_backup.sql';
                                var disposition = response.headers.get('Content-Disposition') || '';
                                var match = disposition.match(/filename="?([^"]+)"?/);
                                if (match) {
                                    filename = match[1];
                                }

                                return response.blob().then(function (blob) {
                                    return { blob: blob, filename: filename };
                                });
                            })
                            .then(function (result) {
                                var url = URL.createObjectURL(result.blob);
                                var link = document.createElement('a');
                                link.href = url;
                                link.download = result.filename;
                                document.body.appendChild(link);
                                link.click();
                                link.remove();
                                URL.revokeObjectURL(url);

                                showToast('success', 'DATABASE BACKUP DOWNLOADED.');
                                modalClose();
                            })
                            .catch(function (err) {
                                submitting = false;
                                showToast('error', err.message || 'THE BACKUP COULD NOT BE CREATED. PLEASE TRY AGAIN.');
                            });
                    },
                },
            ],
        });
    }

    // Step 1: a credentials-only prompt, verified server-side before the
    // actual key-configuration modal ever loads — so a shoulder-surfed or
    // left-open Users page doesn't expose which AI keys are configured
    // without first re-authenticating.
    function openAiSettingsCredentialModal() {
        var submitting = false;
        var bodyHtml =
            '<div class="space-y-4">' +
            modalField({ id: 'modal-ai-verify-username', label: 'USERNAME', required: true, icon: 'user' }) +
            modalField({ id: 'modal-ai-verify-password', label: 'PASSWORD', required: true, type: 'password', icon: 'lock' }) +
            '</div>';

        showModal({
            icon: modalHeaderIcon('lock'),
            title: 'CONFIRM ADMINISTRATOR IDENTITY',
            subtitle: 'For security, re-enter your credentials to view or change the AI extraction API keys.',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: MODAL_BTN.secondary },
                {
                    label: 'CONTINUE',
                    icon: modalButtonIcon('check'),
                    className: MODAL_BTN.primary,
                    onClick: function (modalClose) {
                        if (submitting) {
                            return;
                        }
                        submitting = true;

                        var username = document.getElementById('modal-ai-verify-username').value;
                        var password = document.getElementById('modal-ai-verify-password').value;

                        postJson(config.apiBase + '/api/verify_administrator.php', {
                            csrf_token: config.csrfToken,
                            username: username,
                            password: password,
                        })
                            .then(function (data) {
                                if (data.status === 'success') {
                                    modalClose();
                                    openAiSettingsModal(username, password);
                                } else {
                                    submitting = false;
                                    showToast('error', data.message);
                                }
                            })
                            .catch(function () {
                                submitting = false;
                                showToast('error', 'IDENTITY COULD NOT BE VERIFIED. PLEASE TRY AGAIN.');
                            });
                    },
                },
            ],
        });
    }

    // Step 2: the actual key-configuration modal, only reachable after
    // openAiSettingsCredentialModal() above has verified the admin's
    // credentials. Those same credentials ride along with the final save so
    // api/update_gemini_keys.php can independently re-verify them too,
    // rather than trusting a client-side "already verified" flag.
    function openAiSettingsModal(verifiedUsername, verifiedPassword) {
        var submitting = false;
        var status = config.geminiKeyStatus || {};
        var key1Placeholder = status.key1 && status.key1.configured ? 'CURRENT: ' + status.key1.masked : 'NOT CONFIGURED';
        var key2Placeholder = status.key2 && status.key2.configured ? 'CURRENT: ' + status.key2.masked : 'NOT CONFIGURED';
        var key3Placeholder = status.key3 && status.key3.configured ? 'CURRENT: ' + status.key3.masked : 'NOT CONFIGURED';
        var key4Placeholder = status.key4 && status.key4.configured ? 'CURRENT: ' + status.key4.masked : 'NOT CONFIGURED';

        var bodyHtml =
            '<div class="space-y-4">' +
            '<p class="rounded-lg bg-gray-50 dark:bg-white/5 px-3 py-2 text-sm text-gray-700 dark:text-gray-300">CONFIGURE THE GEMINI API KEY(S) USED FOR AI-BASED PDF EXTRACTION. THE SAVED VALUE TAKES EFFECT IMMEDIATELY — NO SERVER RESTART NEEDED.</p>' +
            modalField({ id: 'modal-ai-key-1', label: 'GEMINI API KEY', optional: true, type: 'password', icon: 'lock', placeholder: key1Placeholder, note: 'Leave blank to keep the current value.' }) +
            modalField({ id: 'modal-ai-key-2', label: 'GEMINI API KEY 2 (QUOTA FALLBACK)', optional: true, type: 'password', icon: 'lock', placeholder: key2Placeholder, note: 'Leave blank to keep the current value.' }) +
            modalField({ id: 'modal-ai-key-3', label: 'GEMINI API KEY 3 (QUOTA FALLBACK)', optional: true, type: 'password', icon: 'lock', placeholder: key3Placeholder, note: 'Leave blank to keep the current value.' }) +
            modalField({ id: 'modal-ai-key-4', label: 'GEMINI API KEY 4 (QUOTA FALLBACK)', optional: true, type: 'password', icon: 'lock', placeholder: key4Placeholder, note: 'Leave blank to keep the current value.' }) +
            '</div>';

        showModal({
            icon: modalHeaderIcon('lock'),
            title: 'AI EXTRACTION SETTINGS',
            subtitle: 'Identity confirmed. Leave a field blank to keep its current value.',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: MODAL_BTN.secondary },
                {
                    label: 'SAVE SETTINGS',
                    icon: modalButtonIcon('check'),
                    className: MODAL_BTN.primary,
                    onClick: function (modalClose) {
                        if (submitting) {
                            return;
                        }
                        submitting = true;

                        postJson(config.apiBase + '/api/update_gemini_keys.php', {
                            csrf_token: config.csrfToken,
                            username: verifiedUsername,
                            password: verifiedPassword,
                            gemini_api_key: document.getElementById('modal-ai-key-1').value,
                            gemini_api_key_2: document.getElementById('modal-ai-key-2').value,
                            gemini_api_key_3: document.getElementById('modal-ai-key-3').value,
                            gemini_api_key_4: document.getElementById('modal-ai-key-4').value,
                        })
                            .then(function (data) {
                                if (data.status === 'success') {
                                    showToast('success', data.message);
                                    modalClose();
                                    reload();
                                } else {
                                    submitting = false;
                                    showToast('error', data.message);
                                }
                            })
                            .catch(function () {
                                submitting = false;
                                showToast('error', 'THE SETTINGS COULD NOT BE SAVED. PLEASE TRY AGAIN.');
                            });
                    },
                },
            ],
        });
    }

    var backupBtn = document.getElementById('create-db-backup-btn');
    if (backupBtn) {
        backupBtn.addEventListener('click', openBackupModal);
    }

    var aiSettingsBtn = document.getElementById('configure-ai-settings-btn');
    if (aiSettingsBtn) {
        aiSettingsBtn.addEventListener('click', openAiSettingsCredentialModal);
    }

});
