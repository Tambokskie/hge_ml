document.addEventListener('DOMContentLoaded', function () {
    var config = window.HGE_RECYCLE_BIN;
    var restoreBtn = document.getElementById('restore-selected-btn');
    var purgeBtn = document.getElementById('purge-selected-btn');
    var selectAll = document.getElementById('bin-select-all');
    if (!config || !restoreBtn || !purgeBtn) {
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

    function checkboxes() {
        return Array.prototype.slice.call(document.querySelectorAll('.bin-checkbox'));
    }

    function selectedIds() {
        return checkboxes().filter(function (cb) { return cb.checked; }).map(function (cb) { return cb.value; });
    }

    function refresh() {
        var count = selectedIds().length;
        restoreBtn.disabled = count === 0;
        purgeBtn.disabled = count === 0;
        restoreBtn.textContent = count > 0 ? 'RESTORE SELECTED (' + count + ')' : 'RESTORE SELECTED';
        purgeBtn.textContent = count > 0 ? 'DELETE PERMANENTLY (' + count + ')' : 'DELETE PERMANENTLY';
        if (selectAll) {
            var boxes = checkboxes();
            selectAll.checked = boxes.length > 0 && count === boxes.length;
        }
    }

    document.addEventListener('change', function (event) {
        if (event.target === selectAll) {
            checkboxes().forEach(function (cb) { cb.checked = selectAll.checked; });
            refresh();
        } else if (event.target.classList.contains('bin-checkbox')) {
            refresh();
        }
    });
    refresh();

    function reloadSoon() {
        setTimeout(function () { window.location.reload(); }, 900);
    }

    restoreBtn.addEventListener('click', function () {
        var ids = selectedIds();
        if (ids.length === 0) {
            return;
        }

        showModal({
            icon: modalHeaderIcon('check'),
            iconClassName: 'bg-green-500/10 text-green-600 dark:text-green-400',
            title: 'RESTORE RECORDS',
            subtitle: 'The selected records go back to the Look-Up list exactly as they were.',
            bodyHtml: '<p class="rounded-lg bg-gray-50 dark:bg-white/5 px-3 py-2 text-sm text-gray-700 dark:text-gray-300">RESTORE <span class="font-medium text-gray-900 dark:text-white">' + ids.length + '</span> RECORD(S)?</p>',
            buttons: [
                { label: 'CANCEL', className: MODAL_BTN.secondary },
                {
                    label: 'RESTORE',
                    className: MODAL_BTN.primary,
                    onClick: function (modalClose) {
                        postJson(config.apiBase + '/api/restore_graduates.php', { csrf_token: config.csrfToken, ids: ids.join(',') })
                            .then(function (data) {
                                if (data.status === 'success') {
                                    showToast('success', data.message);
                                    modalClose();
                                    reloadSoon();
                                } else {
                                    showToast('error', data.message);
                                }
                            })
                            .catch(function () {
                                showToast('error', 'THE RECORDS COULD NOT BE RESTORED. PLEASE TRY AGAIN.');
                            });
                    },
                },
            ],
        });
    });

    purgeBtn.addEventListener('click', function () {
        var ids = selectedIds();
        if (ids.length === 0) {
            return;
        }

        var submitting = false;
        var bodyHtml =
            '<div class="space-y-4">' +
            '<p class="rounded-lg bg-gray-50 dark:bg-white/5 px-3 py-2 text-sm text-gray-700 dark:text-gray-300">PERMANENTLY DELETE <span class="font-medium text-gray-900 dark:text-white">' + ids.length + '</span> RECORD(S)? THIS CANNOT BE UNDONE.</p>' +
            modalField({ id: 'modal-purge-reason', label: 'REASON FOR DELETION', required: true, icon: 'pen', placeholder: 'E.G. CONFIRMED DUPLICATE', uppercase: true, note: 'PLAIN TEXT ONLY — NOT "N/A", "-", OR OTHER SPECIAL CHARACTERS.' }) +
            '</div>';

        showModal({
            icon: modalHeaderIcon('trash'),
            iconClassName: 'bg-csc-red-600/10 text-csc-red-600 dark:text-csc-red-400',
            title: 'DELETE PERMANENTLY',
            subtitle: 'State why these records are being deleted for good. This will be recorded in the activity log.',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: MODAL_BTN.secondary },
                {
                    label: 'DELETE PERMANENTLY',
                    icon: modalButtonIcon('trash'),
                    className: MODAL_BTN.danger,
                    onClick: function (modalClose) {
                        if (submitting) {
                            return;
                        }
                        var reason = document.getElementById('modal-purge-reason').value;
                        if (!isValidRemovalReason(reason)) {
                            showToast('error', 'PLEASE ENTER A VALID REASON.', 'PLAIN TEXT ONLY — NOT "N/A", "-", OR OTHER SPECIAL CHARACTERS.');
                            return;
                        }
                        submitting = true;
                        postJson(config.apiBase + '/api/purge_recycle_bin.php', { csrf_token: config.csrfToken, ids: ids.join(','), reason: reason.trim() })
                            .then(function (data) {
                                if (data.status === 'success') {
                                    showToast('success', data.message);
                                    modalClose();
                                    reloadSoon();
                                } else {
                                    submitting = false;
                                    showToast('error', data.message);
                                }
                            })
                            .catch(function () {
                                submitting = false;
                                showToast('error', 'THE ITEMS COULD NOT BE DELETED. PLEASE TRY AGAIN.');
                            });
                    },
                },
            ],
        });
    });
});
