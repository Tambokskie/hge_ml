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
            '<div class="space-y-3">' +
            '<div><label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">NAME</label>' +
            '<input id="modal-name" type="text" class="w-full rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm"></div>' +
            '<div><label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">USERNAME</label>' +
            '<input id="modal-username" type="text" class="w-full rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm"></div>' +
            '<div><label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">EMAIL</label>' +
            '<input id="modal-email" type="email" class="w-full rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm"></div>' +
            '<div><label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">PASSWORD</label>' +
            '<input id="modal-password" type="password" class="w-full rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm"></div>' +
            '<div><label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">ROLE</label>' +
            '<select id="modal-role" class="w-full rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm">' +
            '<option value="user">USER</option><option value="administrator">ADMINISTRATOR</option></select></div>' +
            '</div>';

        showModal({
            title: 'CREATE USER',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: 'rounded-lg px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5' },
                {
                    label: 'CREATE',
                    className: 'rounded-lg px-4 py-2 text-sm bg-csc-navy-600 hover:bg-csc-navy-500 text-white',
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

        var bodyHtml =
            '<div class="space-y-3">' +
            '<div><label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">NAME</label>' +
            '<p class="text-sm text-gray-900 dark:text-white">' + escapeHtml(defaults.name) + ' (' + escapeHtml(defaults.username) + ')</p></div>' +
            '<div><label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">ROLE</label>' +
            '<select id="modal-role" class="w-full rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm"' + (isSelf ? ' disabled' : '') + '>' + roleOptions + '</select>' +
            (isSelf ? '<p class="text-xs text-gray-600 dark:text-gray-500 mt-1">YOU CANNOT CHANGE YOUR OWN ROLE.</p>' : '') +
            '</div>' +
            '<div><label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">STATUS</label>' +
            '<select id="modal-status" class="w-full rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm"' + (isSelf ? ' disabled' : '') + '>' +
            '<option value="active"' + (defaults.status === 'active' ? ' selected' : '') + '>ACTIVE</option>' +
            '<option value="disabled"' + (defaults.status === 'disabled' ? ' selected' : '') + '>DISABLED</option></select>' +
            (isSelf ? '<p class="text-xs text-gray-600 dark:text-gray-500 mt-1">YOU CANNOT DISABLE YOUR OWN ACCOUNT.</p>' : '') +
            '</div>' +
            '<div><label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">RESET PASSWORD (LEAVE BLANK TO KEEP CURRENT)</label>' +
            '<input id="modal-new-password" type="password" class="w-full rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm"></div>' +
            '</div>';

        showModal({
            title: 'EDIT USER',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: 'rounded-lg px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5' },
                {
                    label: 'SAVE',
                    className: 'rounded-lg px-4 py-2 text-sm bg-csc-navy-600 hover:bg-csc-navy-500 text-white',
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
            '<p class="mb-4">FOR SECURITY, CONFIRM YOUR ADMINISTRATOR CREDENTIALS TO DOWNLOAD A FULL DATABASE BACKUP.</p>' +
            '<div class="text-left space-y-3">' +
            '<div><label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">USERNAME</label>' +
            '<input id="modal-backup-username" type="text" class="w-full rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm"></div>' +
            '<div><label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">PASSWORD</label>' +
            '<input id="modal-backup-password" type="password" class="w-full rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm"></div>' +
            '</div>';

        showModal({
            icon: '<svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 10-8 0v4h8z" /></svg>',
            title: 'CONFIRM ADMINISTRATOR IDENTITY',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: 'flex-1 rounded-full border border-gray-300 dark:border-white/15 px-4 py-2.5 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 transition-colors' },
                {
                    label: 'CREATE DB BACKUP',
                    className: 'flex-1 rounded-full px-4 py-2.5 text-sm font-medium bg-csc-navy-600 hover:bg-csc-navy-500 text-white transition-colors',
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
            '<div class="space-y-3">' +
            '<p class="text-sm text-gray-700 dark:text-gray-300">PERMANENTLY REMOVE <span class="text-gray-900 dark:text-white font-medium">' + escapeHtml(target.name) + '</span>? THIS CANNOT BE UNDONE.</p>' +
            '<div><label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">ENTER YOUR PASSWORD TO CONFIRM</label>' +
            '<input id="modal-remove-password" type="password" class="w-full rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm"></div>' +
            '</div>';

        showModal({
            title: 'REMOVE USER',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: 'rounded-lg px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5' },
                {
                    label: 'CONFIRM',
                    className: 'rounded-lg px-4 py-2 text-sm bg-csc-red-600 hover:bg-csc-red-500 text-white',
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
