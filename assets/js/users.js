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
            '<div><label class="block text-xs text-gray-400 mb-1">NAME</label>' +
            '<input id="modal-name" type="text" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm"></div>' +
            '<div><label class="block text-xs text-gray-400 mb-1">USERNAME</label>' +
            '<input id="modal-username" type="text" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm"></div>' +
            '<div><label class="block text-xs text-gray-400 mb-1">EMAIL</label>' +
            '<input id="modal-email" type="email" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm"></div>' +
            '<div><label class="block text-xs text-gray-400 mb-1">PASSWORD</label>' +
            '<input id="modal-password" type="password" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm"></div>' +
            '<div><label class="block text-xs text-gray-400 mb-1">ROLE</label>' +
            '<select id="modal-role" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm">' +
            '<option value="user">USER</option><option value="administrator">ADMINISTRATOR</option></select></div>' +
            '</div>';

        showModal({
            title: 'CREATE USER',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: 'rounded-lg px-4 py-2 text-sm text-gray-300 hover:bg-white/5' },
                {
                    label: 'CREATE',
                    className: 'rounded-lg px-4 py-2 text-sm bg-accent-600 hover:bg-accent-500 text-white',
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
            '<div><label class="block text-xs text-gray-400 mb-1">NAME</label>' +
            '<p class="text-sm text-white">' + escapeHtml(defaults.name) + ' (' + escapeHtml(defaults.username) + ')</p></div>' +
            '<div><label class="block text-xs text-gray-400 mb-1">ROLE</label>' +
            '<select id="modal-role" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm"' + (isSelf ? ' disabled' : '') + '>' + roleOptions + '</select>' +
            (isSelf ? '<p class="text-xs text-gray-500 mt-1">YOU CANNOT CHANGE YOUR OWN ROLE.</p>' : '') +
            '</div>' +
            '<div><label class="block text-xs text-gray-400 mb-1">STATUS</label>' +
            '<select id="modal-status" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm"' + (isSelf ? ' disabled' : '') + '>' +
            '<option value="active"' + (defaults.status === 'active' ? ' selected' : '') + '>ACTIVE</option>' +
            '<option value="disabled"' + (defaults.status === 'disabled' ? ' selected' : '') + '>DISABLED</option></select>' +
            (isSelf ? '<p class="text-xs text-gray-500 mt-1">YOU CANNOT DISABLE YOUR OWN ACCOUNT.</p>' : '') +
            '</div>' +
            '<div><label class="block text-xs text-gray-400 mb-1">RESET PASSWORD (LEAVE BLANK TO KEEP CURRENT)</label>' +
            '<input id="modal-new-password" type="password" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm"></div>' +
            '</div>';

        showModal({
            title: 'EDIT USER',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: 'rounded-lg px-4 py-2 text-sm text-gray-300 hover:bg-white/5' },
                {
                    label: 'SAVE',
                    className: 'rounded-lg px-4 py-2 text-sm bg-accent-600 hover:bg-accent-500 text-white',
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

    var createBtn = document.getElementById('create-user-btn');
    if (createBtn) {
        createBtn.addEventListener('click', openCreateModal);
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
});
