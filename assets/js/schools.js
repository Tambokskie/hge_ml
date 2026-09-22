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

    function enforceUppercase(input) {
        if (!input) {
            return;
        }
        input.value = input.value.toUpperCase();
        input.addEventListener('input', function () {
            var pos = input.selectionStart;
            input.value = input.value.toUpperCase();
            input.setSelectionRange(pos, pos);
        });
    }

    function openConfirmModal(title, rows, onConfirm, onGoBack) {
        var bodyHtml = '<div class="space-y-2">' +
            rows.map(function (row) {
                return '<div class="flex justify-between gap-4 border-b border-gray-100 dark:border-white/5 pb-1">' +
                    '<span class="text-gray-500 dark:text-gray-400">' + escapeHtml(row.label) + '</span>' +
                    '<span class="text-gray-900 dark:text-white text-right">' + escapeHtml(row.value) + '</span></div>';
            }).join('') +
            '</div>';

        showModal({
            title: title,
            bodyHtml: bodyHtml,
            buttons: [
                {
                    label: 'GO BACK',
                    className: 'rounded-lg px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5',
                    onClick: function (modalClose) {
                        modalClose();
                        onGoBack();
                    },
                },
                {
                    label: 'CONFIRM',
                    className: 'rounded-lg px-4 py-2 text-sm bg-accent-600 hover:bg-accent-500 text-white',
                    onClick: onConfirm,
                },
            ],
        });
    }

    function openCreateModal(prefill) {
        var submitting = false;
        prefill = prefill || { name: '', address: '', campus: '' };
        var bodyHtml =
            '<div class="space-y-3">' +
            '<div><label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">SCHOOL NAME</label>' +
            '<input id="modal-name" type="text" value="' + escapeHtml(prefill.name) + '" class="w-full rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm"></div>' +
            '<div><label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">ADDRESS</label>' +
            '<input id="modal-address" type="text" value="' + escapeHtml(prefill.address) + '" class="w-full rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm"></div>' +
            '<div><label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">CAMPUS (OPTIONAL)</label>' +
            '<input id="modal-campus" type="text" value="' + escapeHtml(prefill.campus) + '" class="w-full rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm"></div>' +
            '</div>';

        showModal({
            title: 'CREATE SCHOOL',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: 'rounded-lg px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5' },
                {
                    label: 'CREATE',
                    className: 'rounded-lg px-4 py-2 text-sm bg-accent-600 hover:bg-accent-500 text-white',
                    onClick: function (modalClose) {
                        var values = {
                            name: document.getElementById('modal-name').value,
                            address: document.getElementById('modal-address').value,
                            campus: document.getElementById('modal-campus').value,
                        };
                        modalClose();
                        openConfirmModal(
                            'CONFIRM NEW SCHOOL',
                            [
                                { label: 'SCHOOL NAME', value: values.name },
                                { label: 'ADDRESS', value: values.address },
                                { label: 'CAMPUS', value: values.campus !== '' ? values.campus : '—' },
                            ],
                            function (confirmModalClose) {
                                if (submitting) {
                                    return;
                                }
                                submitting = true;
                                postJson(config.apiBase + '/api/create_school.php', {
                                    csrf_token: config.csrfToken,
                                    name: values.name,
                                    address: values.address,
                                    campus: values.campus,
                                })
                                    .then(function (data) {
                                        if (data.status === 'success') {
                                            showToast('success', data.message);
                                            confirmModalClose();
                                            reload();
                                        } else {
                                            submitting = false;
                                            showToast('error', data.message);
                                        }
                                    })
                                    .catch(function () {
                                        submitting = false;
                                        showToast('error', 'THE SCHOOL COULD NOT BE CREATED. PLEASE TRY AGAIN.');
                                    });
                            },
                            function () {
                                openCreateModal(values);
                            }
                        );
                    },
                },
            ],
        });

        enforceUppercase(document.getElementById('modal-name'));
        enforceUppercase(document.getElementById('modal-address'));
        enforceUppercase(document.getElementById('modal-campus'));
    }

    function openEditModal(defaults) {
        var submitting = false;
        var bodyHtml =
            '<div class="space-y-3">' +
            '<div><label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">SCHOOL NAME</label>' +
            '<input id="modal-name" type="text" value="' + escapeHtml(defaults.name) + '" class="w-full rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm"></div>' +
            '<div><label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">ADDRESS</label>' +
            '<input id="modal-address" type="text" value="' + escapeHtml(defaults.address) + '" class="w-full rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm"></div>' +
            '<div><label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">CAMPUS (OPTIONAL)</label>' +
            '<input id="modal-campus" type="text" value="' + escapeHtml(defaults.campus) + '" class="w-full rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm"></div>' +
            '<div><label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">STATUS</label>' +
            '<select id="modal-status" class="w-full rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm">' +
            '<option value="active"' + (defaults.status === 'active' ? ' selected' : '') + '>ACTIVE</option>' +
            '<option value="disabled"' + (defaults.status === 'disabled' ? ' selected' : '') + '>DISABLED</option></select></div>' +
            '</div>';

        showModal({
            title: 'EDIT SCHOOL',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: 'rounded-lg px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5' },
                {
                    label: 'SAVE',
                    className: 'rounded-lg px-4 py-2 text-sm bg-accent-600 hover:bg-accent-500 text-white',
                    onClick: function (modalClose) {
                        var values = {
                            schoolId: defaults.schoolId,
                            name: document.getElementById('modal-name').value,
                            address: document.getElementById('modal-address').value,
                            campus: document.getElementById('modal-campus').value,
                            status: document.getElementById('modal-status').value,
                        };
                        modalClose();
                        openConfirmModal(
                            'CONFIRM SCHOOL CHANGES',
                            [
                                { label: 'SCHOOL NAME', value: values.name },
                                { label: 'ADDRESS', value: values.address },
                                { label: 'CAMPUS', value: values.campus !== '' ? values.campus : '—' },
                                { label: 'STATUS', value: values.status.toUpperCase() },
                            ],
                            function (confirmModalClose) {
                                if (submitting) {
                                    return;
                                }
                                submitting = true;
                                postJson(config.apiBase + '/api/update_school.php', {
                                    csrf_token: config.csrfToken,
                                    school_id: values.schoolId,
                                    name: values.name,
                                    address: values.address,
                                    campus: values.campus,
                                    status: values.status,
                                })
                                    .then(function (data) {
                                        if (data.status === 'success') {
                                            showToast('success', data.message);
                                            confirmModalClose();
                                            reload();
                                        } else {
                                            submitting = false;
                                            showToast('error', data.message);
                                        }
                                    })
                                    .catch(function () {
                                        submitting = false;
                                        showToast('error', 'THE SCHOOL COULD NOT BE UPDATED. PLEASE TRY AGAIN.');
                                    });
                            },
                            function () {
                                openEditModal(values);
                            }
                        );
                    },
                },
            ],
        });

        enforceUppercase(document.getElementById('modal-name'));
        enforceUppercase(document.getElementById('modal-address'));
        enforceUppercase(document.getElementById('modal-campus'));
    }

    function openRemoveConfirmModal(target) {
        var submitting = false;
        var displayName = target.campus !== '' ? target.name + '-' + target.campus : target.name;
        var bodyHtml =
            '<div class="space-y-3">' +
            '<p class="text-sm text-gray-700 dark:text-gray-300">PERMANENTLY REMOVE <span class="text-gray-900 dark:text-white font-medium">' + escapeHtml(displayName) + '</span>? THIS CANNOT BE UNDONE.</p>' +
            '<div><label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">ENTER YOUR PASSWORD TO CONFIRM</label>' +
            '<input id="modal-remove-password" type="password" class="w-full rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm"></div>' +
            '</div>';

        showModal({
            title: 'REMOVE SCHOOL',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: 'rounded-lg px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5' },
                {
                    label: 'CONFIRM',
                    className: 'rounded-lg px-4 py-2 text-sm bg-red-600 hover:bg-red-500 text-white',
                    onClick: function (modalClose) {
                        if (submitting) {
                            return;
                        }
                        submitting = true;
                        var password = document.getElementById('modal-remove-password').value;
                        postJson(config.apiBase + '/api/remove_school.php', {
                            csrf_token: config.csrfToken,
                            school_id: target.schoolId,
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
                                showToast('error', 'THE SCHOOL COULD NOT BE REMOVED. PLEASE TRY AGAIN.');
                            });
                    },
                },
            ],
        });
    }

    var createBtn = document.getElementById('create-school-btn');
    if (createBtn) {
        createBtn.addEventListener('click', function () {
            openCreateModal();
        });
    }

    document.querySelectorAll('.edit-school-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openEditModal({
                schoolId: btn.dataset.schoolId,
                name: btn.dataset.name,
                address: btn.dataset.address,
                campus: btn.dataset.campus,
                status: btn.dataset.status,
            });
        });
    });

    var removeBtn = document.getElementById('remove-school-btn');
    var checkboxes = document.querySelectorAll('.school-checkbox');

    function updateRemoveButtonState() {
        if (!removeBtn) {
            return;
        }
        var checked = document.querySelector('.school-checkbox:checked');
        removeBtn.disabled = !checked;
    }

    checkboxes.forEach(function (cb) {
        cb.addEventListener('change', function () {
            if (cb.checked) {
                checkboxes.forEach(function (other) {
                    if (other !== cb) {
                        other.checked = false;
                    }
                });
            }
            updateRemoveButtonState();
        });
    });

    if (removeBtn) {
        removeBtn.addEventListener('click', function () {
            var checked = document.querySelector('.school-checkbox:checked');
            if (!checked) {
                return;
            }
            openRemoveConfirmModal({
                schoolId: checked.dataset.schoolId,
                name: checked.dataset.name,
                campus: checked.dataset.campus,
            });
        });
    }

    updateRemoveButtonState();
});
