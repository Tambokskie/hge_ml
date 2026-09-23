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
                    className: 'rounded-lg px-4 py-2 text-sm bg-csc-navy-600 hover:bg-csc-navy-500 text-white',
                    onClick: onConfirm,
                },
            ],
        });
    }

    function openCreateModal(prefill) {
        var submitting = false;
        prefill = prefill || { name: '', address: '', campus: '' };
        var bodyHtml =
            '<div class="space-y-4">' +
            modalField({ id: 'modal-name', label: 'SCHOOL NAME', required: true, icon: 'building', value: prefill.name, placeholder: 'Enter school name' }) +
            modalField({ id: 'modal-address', label: 'ADDRESS', required: true, icon: 'location', value: prefill.address, placeholder: 'Enter school address' }) +
            modalField({ id: 'modal-campus', label: 'CAMPUS', optional: true, icon: 'building', value: prefill.campus, placeholder: 'Enter campus name (optional)' }) +
            '</div>';

        showModal({
            icon: modalHeaderIcon('building'),
            title: 'CREATE SCHOOL',
            subtitle: 'Add a school where Latin Honor graduates are recorded.',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: MODAL_BTN.secondary },
                {
                    label: 'CREATE SCHOOL',
                    icon: modalButtonIcon('plus'),
                    className: MODAL_BTN.primary,
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
        var statusOptionsHtml =
            '<option value="active"' + (defaults.status === 'active' ? ' selected' : '') + '>ACTIVE</option>' +
            '<option value="disabled"' + (defaults.status === 'disabled' ? ' selected' : '') + '>DISABLED</option>';
        var bodyHtml =
            '<div class="space-y-4">' +
            modalField({ id: 'modal-name', label: 'SCHOOL NAME', required: true, icon: 'building', value: defaults.name }) +
            modalField({ id: 'modal-address', label: 'ADDRESS', required: true, icon: 'location', value: defaults.address }) +
            modalField({ id: 'modal-campus', label: 'CAMPUS', optional: true, icon: 'building', value: defaults.campus }) +
            modalField({ id: 'modal-status', label: 'STATUS', type: 'select', icon: 'tag', optionsHtml: statusOptionsHtml }) +
            '</div>';

        showModal({
            icon: modalHeaderIcon('building'),
            title: 'EDIT SCHOOL',
            subtitle: 'Update this school’s details.',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: MODAL_BTN.secondary },
                {
                    label: 'SAVE CHANGES',
                    icon: modalButtonIcon('check'),
                    className: MODAL_BTN.primary,
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
            '<div class="space-y-4">' +
            '<p class="rounded-lg bg-gray-50 dark:bg-white/5 px-3 py-2 text-sm text-gray-700 dark:text-gray-300">PERMANENTLY REMOVE <span class="font-medium text-gray-900 dark:text-white">' + escapeHtml(displayName) + '</span>? THIS CANNOT BE UNDONE.</p>' +
            modalField({ id: 'modal-remove-password', label: 'ENTER YOUR PASSWORD TO CONFIRM', required: true, type: 'password', icon: 'lock' }) +
            '</div>';

        showModal({
            icon: modalHeaderIcon('trash'),
            iconClassName: 'bg-csc-red-600/10 text-csc-red-600 dark:text-csc-red-400',
            title: 'REMOVE SCHOOL',
            subtitle: 'This action permanently deletes the school and cannot be undone.',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: MODAL_BTN.secondary },
                {
                    label: 'REMOVE SCHOOL',
                    icon: modalButtonIcon('trash'),
                    className: MODAL_BTN.danger,
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
