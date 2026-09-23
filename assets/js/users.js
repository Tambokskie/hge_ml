document.addEventListener('DOMContentLoaded', function () {
    var config = window.HGE_USERS;
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

    function openCreateModal() {
        var bodyHtml =
            '<div class="space-y-4">' +
            modalField({ id: 'modal-name', label: 'NAME', required: true, icon: 'user' }) +
            modalField({ id: 'modal-username', label: 'USERNAME', required: true, icon: 'user' }) +
            modalField({ id: 'modal-email', label: 'EMAIL', required: true, type: 'email', icon: 'mail' }) +
            modalField({ id: 'modal-password', label: 'PASSWORD', required: true, type: 'password', icon: 'lock' }) +
            modalField({ id: 'modal-role', label: 'ROLE', type: 'select', icon: 'tag', optionsHtml: '<option value="user">USER</option><option value="administrator">ADMINISTRATOR</option>' }) +
            '</div>';

        showModal({
            icon: modalHeaderIcon('user'),
            title: 'CREATE USER',
            subtitle: 'Add a new administrator or user account.',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: MODAL_BTN.secondary },
                {
                    label: 'CREATE USER',
                    icon: modalButtonIcon('plus'),
                    className: MODAL_BTN.primary,
                    onClick: function (modalClose) {
                        postJson(config.apiBase + '/api/create_user.php', {
                            csrf_token: config.csrfToken,
                            name: document.getElementById('modal-name').value,
                            username: document.getElementById('modal-username').value,
                            email: document.getElementById('modal-email').value,
                            password: document.getElementById('modal-password').value,
                            role: document.getElementById('modal-role').value,
                        })
                            .then(function (data) {
                                if (data.status === 'success') {
                                    showToast('success', data.message);
                                    modalClose();
                                    reload();
                                } else {
                                    showToast('error', data.message);
                                }
                            })
                            .catch(function () {
                                showToast('error', 'THE USER COULD NOT BE CREATED. PLEASE TRY AGAIN.');
                            });
                    },
                },
            ],
        });
    }

    function openEditModal(defaults) {
        var isSelf = defaults.isSelf;
        var roleOptions = ['user', 'administrator'].map(function (opt) {
            var selected = opt === defaults.role ? ' selected' : '';
            var disabled = isSelf ? ' disabled' : '';
            return '<option value="' + opt + '"' + selected + disabled + '>' + opt.toUpperCase() + '</option>';
        }).join('');

        var statusOptionsHtml =
            '<option value="active"' + (defaults.status === 'active' ? ' selected' : '') + '>ACTIVE</option>' +
            '<option value="disabled"' + (defaults.status === 'disabled' ? ' selected' : '') + '>DISABLED</option>';

        var bodyHtml =
            '<div class="space-y-4">' +
            modalField({ id: 'modal-account', label: 'NAME', type: 'static', value: defaults.name + ' (' + defaults.username + ')' }) +
            modalField({
                id: 'modal-role', label: 'ROLE', type: 'select', icon: 'tag', optionsHtml: roleOptions,
                attrs: isSelf ? ' disabled' : '', note: isSelf ? 'YOU CANNOT CHANGE YOUR OWN ROLE.' : '',
            }) +
            modalField({
                id: 'modal-status', label: 'STATUS', type: 'select', icon: 'tag', optionsHtml: statusOptionsHtml,
                attrs: isSelf ? ' disabled' : '', note: isSelf ? 'YOU CANNOT DISABLE YOUR OWN ACCOUNT.' : '',
            }) +
            modalField({ id: 'modal-new-password', label: 'RESET PASSWORD', optional: true, type: 'password', icon: 'lock', note: 'Leave blank to keep the current password.' }) +
            '</div>';

        showModal({
            icon: modalHeaderIcon('user'),
            title: 'EDIT USER',
            subtitle: 'Update this account’s role, status, or password.',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: MODAL_BTN.secondary },
                {
                    label: 'SAVE CHANGES',
                    icon: modalButtonIcon('check'),
                    className: MODAL_BTN.primary,
                    onClick: function (modalClose) {
                        var payload = {
                            csrf_token: config.csrfToken,
                            user_id: defaults.userId,
                        };
                        if (!isSelf) {
                            payload.role = document.getElementById('modal-role').value;
                            payload.status = document.getElementById('modal-status').value;
                        }
                        var newPassword = document.getElementById('modal-new-password').value;
                        if (newPassword !== '') {
                            payload.new_password = newPassword;
                        }

                        postJson(config.apiBase + '/api/update_user.php', payload)
                            .then(function (data) {
                                if (data.status === 'success') {
                                    showToast('success', data.message);
                                    modalClose();
                                    reload();
                                } else {
                                    showToast('error', data.message);
                                }
                            })
                            .catch(function () {
                                showToast('error', 'THE USER COULD NOT BE UPDATED. PLEASE TRY AGAIN.');
                            });
                    },
                },
            ],
        });
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

    function openRemoveConfirmModal(target) {
        var submitting = false;
        var bodyHtml =
            '<div class="space-y-4">' +
            '<p class="rounded-lg bg-gray-50 dark:bg-white/5 px-3 py-2 text-sm text-gray-700 dark:text-gray-300">PERMANENTLY REMOVE <span class="font-medium text-gray-900 dark:text-white">' + escapeHtml(target.name) + '</span>? THIS CANNOT BE UNDONE.</p>' +
            modalField({ id: 'modal-remove-password', label: 'ENTER YOUR PASSWORD TO CONFIRM', required: true, type: 'password', icon: 'lock' }) +
            '</div>';

        showModal({
            icon: modalHeaderIcon('trash'),
            iconClassName: 'bg-csc-red-600/10 text-csc-red-600 dark:text-csc-red-400',
            title: 'REMOVE USER',
            subtitle: 'This action permanently deletes the account and cannot be undone.',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: MODAL_BTN.secondary },
                {
                    label: 'REMOVE USER',
                    icon: modalButtonIcon('trash'),
                    className: MODAL_BTN.danger,
                    onClick: function (modalClose) {
                        if (submitting) {
                            return;
                        }
                        submitting = true;
                        var password = document.getElementById('modal-remove-password').value;
                        postJson(config.apiBase + '/api/remove_user.php', {
                            csrf_token: config.csrfToken,
                            user_id: target.userId,
                            password: password,
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
                                showToast('error', 'THE USER COULD NOT BE REMOVED. PLEASE TRY AGAIN.');
                            });
                    },
                },
            ],
        });
    }

    var createBtn = document.getElementById('create-user-btn');
    if (createBtn) {
        createBtn.addEventListener('click', openCreateModal);
    }

    var backupBtn = document.getElementById('create-db-backup-btn');
    if (backupBtn) {
        backupBtn.addEventListener('click', openBackupModal);
    }

    document.querySelectorAll('.edit-user-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openEditModal({
                userId: btn.dataset.userId,
                name: btn.dataset.name,
                username: btn.dataset.username,
                role: btn.dataset.role,
                status: btn.dataset.status,
                isSelf: btn.dataset.isSelf === '1',
            });
        });
    });

    document.querySelectorAll('.remove-user-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openRemoveConfirmModal({
                userId: btn.dataset.userId,
                name: btn.dataset.name,
            });
        });
    });
});
