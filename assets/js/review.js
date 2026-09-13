document.addEventListener('DOMContentLoaded', function () {
    var config = window.HGE_REVIEW;
    if (!config) {
        return;
    }

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value;
        return div.innerHTML;
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

    function openEditModal(rowIndex, defaults) {
        var isNew = rowIndex === null;
        var honors = ['CUM LAUDE', 'MAGNA CUM LAUDE', 'SUMMA CUM LAUDE'];
        var optionsHtml = honors.map(function (opt) {
            var selected = opt === defaults.honor ? ' selected' : '';
            return '<option value="' + opt + '"' + selected + '>' + opt + '</option>';
        }).join('');

        var bodyHtml =
            '<div class="space-y-3">' +
            '<div><label class="block text-xs text-gray-400 mb-1">GRADUATE NAME</label>' +
            '<input id="modal-graduate-name" type="text" value="' + escapeHtml(defaults.name || '') + '" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm"></div>' +
            '<div><label class="block text-xs text-gray-400 mb-1">LATIN HONOR</label>' +
            '<select id="modal-latin-honor" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm">' + optionsHtml + '</select></div>' +
            '<div><label class="block text-xs text-gray-400 mb-1">DATE OF GRADUATION</label>' +
            '<input id="modal-graduation-date" type="date" value="' + escapeHtml(defaults.date || '') + '" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm"></div>' +
            '<div><label class="block text-xs text-gray-400 mb-1">YEAR GRADUATED</label>' +
            '<input id="modal-graduation-year" type="text" value="' + escapeHtml(defaults.year || '') + '" class="w-full rounded-lg bg-surface-800 border border-white/10 px-3 py-2 text-sm"></div>' +
            '</div>';

        showModal({
            title: isNew ? 'ADD RECORD' : 'EDIT RECORD',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: 'rounded-lg px-4 py-2 text-sm text-gray-300 hover:bg-white/5' },
                {
                    label: 'SAVE',
                    className: 'rounded-lg px-4 py-2 text-sm bg-accent-600 hover:bg-accent-500 text-white',
                    onClick: function (modalClose) {
                        var payload = {
                            csrf_token: config.csrfToken,
                            upload_id: config.uploadId,
                            graduate_name: document.getElementById('modal-graduate-name').value,
                            latin_honor: document.getElementById('modal-latin-honor').value,
                            graduation_date: document.getElementById('modal-graduation-date').value,
                            graduation_year: document.getElementById('modal-graduation-year').value,
                        };
                        if (rowIndex !== null) {
                            payload.row_index = rowIndex;
                        }
                        postJson(config.apiBase + '/api/update_graduate.php', payload)
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
                                showToast('error', 'THE RECORD COULD NOT BE SAVED. PLEASE TRY AGAIN.');
                            });
                    },
                },
            ],
        });
    }

    document.querySelectorAll('.edit-row-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openEditModal(parseInt(btn.dataset.rowIndex, 10), {
                name: btn.dataset.name,
                honor: btn.dataset.honor,
                date: btn.dataset.date,
                year: btn.dataset.year,
            });
        });
    });

    var addBtn = document.getElementById('add-record-btn');
    if (addBtn) {
        addBtn.addEventListener('click', function () {
            openEditModal(null, {});
        });
    }

    document.querySelectorAll('.remove-row-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var rowIndex = parseInt(btn.dataset.rowIndex, 10);
            showModal({
                title: 'REMOVE THIS GRADUATE RECORD?',
                bodyHtml: escapeHtml(btn.dataset.name),
                buttons: [
                    { label: 'CANCEL', className: 'rounded-lg px-4 py-2 text-sm text-gray-300 hover:bg-white/5' },
                    {
                        label: 'REMOVE RECORD',
                        className: 'rounded-lg px-4 py-2 text-sm bg-red-600 hover:bg-red-500 text-white',
                        onClick: function (close) {
                            close();
                            postJson(config.apiBase + '/api/delete_graduate.php', {
                                csrf_token: config.csrfToken,
                                upload_id: config.uploadId,
                                row_index: rowIndex,
                            })
                                .then(function (data) {
                                    if (data.status === 'success') {
                                        showToast('success', data.message);
                                        reload();
                                    } else {
                                        showToast('error', data.message);
                                    }
                                })
                                .catch(function () {
                                    showToast('error', 'THE RECORD COULD NOT BE REMOVED. PLEASE TRY AGAIN.');
                                });
                        },
                    },
                ],
            });
        });
    });

    var saveBtn = document.getElementById('save-records-btn');
    if (saveBtn) {
        saveBtn.addEventListener('click', function () {
            postJson(config.apiBase + '/api/save_records.php', {
                csrf_token: config.csrfToken,
                upload_id: config.uploadId,
            })
                .then(function (data) {
                    if (data.status === 'success') {
                        showToast('success', data.message);
                        window.location.href = config.viewUploadUrl;
                    } else {
                        showToast('error', data.message);
                    }
                })
                .catch(function () {
                    showToast('error', 'RECORDS COULD NOT BE SAVED. PLEASE TRY AGAIN.');
                });
        });
    }
});
