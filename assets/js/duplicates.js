document.addEventListener('DOMContentLoaded', function () {
    var config = window.HGE_DUPLICATES;
    var removeBtn = document.getElementById('remove-selected-btn');
    if (!config || !removeBtn) {
        return;
    }

    var countEl = document.getElementById('selected-count');

    function postJson(url, data) {
        var formData = new FormData();
        Object.keys(data).forEach(function (key) {
            formData.set(key, data[key]);
        });
        return fetch(url, { method: 'POST', body: formData }).then(function (r) {
            return r.json();
        });
    }

    function selectedIds() {
        return Array.prototype.slice.call(document.querySelectorAll('.dup-checkbox:checked')).map(function (cb) {
            return cb.value;
        });
    }

    function refreshCount() {
        var count = selectedIds().length;
        countEl.textContent = count;
        removeBtn.disabled = count === 0;
    }

    document.addEventListener('change', function (event) {
        if (event.target.classList.contains('dup-checkbox')) {
            refreshCount();
        }
    });
    refreshCount();

    removeBtn.addEventListener('click', function () {
        var ids = selectedIds();
        if (ids.length === 0) {
            return;
        }

        var submitting = false;
        var bodyHtml =
            '<div class="space-y-4">' +
            '<p class="rounded-lg bg-gray-50 dark:bg-white/5 px-3 py-2 text-sm text-gray-700 dark:text-gray-300">MOVE <span class="font-medium text-gray-900 dark:text-white">' + ids.length + '</span> DUPLICATE RECORD(S) TO THE RECYCLE BIN? AT LEAST ONE COPY OF EACH GROUP IS KEPT, AND YOU CAN RESTORE THEM LATER.</p>' +
            modalField({ id: 'modal-dup-reason', label: 'REASON FOR REMOVAL', required: true, icon: 'pen', placeholder: 'E.G. DUPLICATE ENTRY', uppercase: true, note: 'PLAIN TEXT ONLY — NOT "N/A", "-", OR OTHER SPECIAL CHARACTERS.' }) +
            '</div>';

        showModal({
            icon: modalHeaderIcon('trash'),
            iconClassName: 'bg-csc-red-600/10 text-csc-red-600 dark:text-csc-red-400',
            title: 'REMOVE DUPLICATE RECORDS',
            subtitle: 'State why these records are being removed. This will be recorded in the activity log.',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: MODAL_BTN.secondary },
                {
                    label: 'MOVE TO RECYCLE BIN',
                    icon: modalButtonIcon('trash'),
                    className: MODAL_BTN.danger,
                    onClick: function (modalClose) {
                        if (submitting) {
                            return;
                        }
                        var reason = document.getElementById('modal-dup-reason').value;
                        if (!isValidRemovalReason(reason)) {
                            showToast('error', 'PLEASE ENTER A VALID REASON FOR REMOVAL.', 'PLAIN TEXT ONLY — NOT "N/A", "-", OR OTHER SPECIAL CHARACTERS.');
                            return;
                        }
                        submitting = true;
                        postJson(config.apiBase + '/api/remove_duplicates.php', {
                            csrf_token: config.csrfToken,
                            ids: ids.join(','),
                            reason: reason.trim(),
                        })
                            .then(function (data) {
                                if (data.status === 'success') {
                                    showToast('success', data.message, data.skipped && data.skipped.length ? data.skipped.join(' ') : undefined);
                                    modalClose();
                                    setTimeout(function () { window.location.reload(); }, 900);
                                } else {
                                    submitting = false;
                                    showToast('error', data.message);
                                }
                            })
                            .catch(function () {
                                submitting = false;
                                showToast('error', 'THE DUPLICATES COULD NOT BE REMOVED. PLEASE TRY AGAIN.');
                            });
                    },
                },
            ],
        });
    });
});
