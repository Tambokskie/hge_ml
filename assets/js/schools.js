document.addEventListener('DOMContentLoaded', function () {
    var config = window.HGE_SCHOOLS;
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
            '<div><label class="block text-xs text-gray-400 mb-1">SCHOOL NAME</label>' +
            '<input id="modal-name" type="text" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm"></div>' +
            '</div>';

        showModal({
            title: 'CREATE SCHOOL',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: 'rounded-lg px-4 py-2 text-sm text-gray-300 hover:bg-white/5' },
                {
                    label: 'CREATE',
                    className: 'rounded-lg px-4 py-2 text-sm bg-accent-600 hover:bg-accent-500 text-white',
                    onClick: function (modalClose) {
                        postJson(config.apiBase + '/api/create_school.php', {
                            csrf_token: config.csrfToken,
                            name: document.getElementById('modal-name').value,
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
                                showToast('error', 'THE SCHOOL COULD NOT BE CREATED. PLEASE TRY AGAIN.');
                            });
                    },
                },
            ],
        });
    }

    function openEditModal(defaults) {
        var bodyHtml =
            '<div class="space-y-3">' +
            '<div><label class="block text-xs text-gray-400 mb-1">SCHOOL NAME</label>' +
            '<input id="modal-name" type="text" value="' + escapeHtml(defaults.name) + '" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm"></div>' +
            '<div><label class="block text-xs text-gray-400 mb-1">STATUS</label>' +
            '<select id="modal-status" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm">' +
            '<option value="active"' + (defaults.status === 'active' ? ' selected' : '') + '>ACTIVE</option>' +
            '<option value="disabled"' + (defaults.status === 'disabled' ? ' selected' : '') + '>DISABLED</option></select></div>' +
            '</div>';

        showModal({
            title: 'EDIT SCHOOL',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: 'rounded-lg px-4 py-2 text-sm text-gray-300 hover:bg-white/5' },
                {
                    label: 'SAVE',
                    className: 'rounded-lg px-4 py-2 text-sm bg-accent-600 hover:bg-accent-500 text-white',
                    onClick: function (modalClose) {
                        postJson(config.apiBase + '/api/update_school.php', {
                            csrf_token: config.csrfToken,
                            school_id: defaults.schoolId,
                            name: document.getElementById('modal-name').value,
                            status: document.getElementById('modal-status').value,
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
                                showToast('error', 'THE SCHOOL COULD NOT BE UPDATED. PLEASE TRY AGAIN.');
                            });
                    },
                },
            ],
        });
    }

    var createBtn = document.getElementById('create-school-btn');
    if (createBtn) {
        createBtn.addEventListener('click', openCreateModal);
    }

    document.querySelectorAll('.edit-school-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openEditModal({
                schoolId: btn.dataset.schoolId,
                name: btn.dataset.name,
                status: btn.dataset.status,
            });
        });
    });
});
