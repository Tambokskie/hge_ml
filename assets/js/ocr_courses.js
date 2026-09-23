document.addEventListener('DOMContentLoaded', function () {
    var config = window.HGE_OCR_COURSES;
    if (!config) {
        return;
    }

    var form = document.getElementById('add-keyword-form');
    var input = document.getElementById('new-keyword');
    var addBtn = document.getElementById('add-keyword-btn');
    var tbody = document.getElementById('keywords-tbody');

    function postJson(url, data) {
        var formData = new FormData();
        Object.keys(data).forEach(function (key) {
            formData.set(key, data[key]);
        });
        return fetch(url, { method: 'POST', body: formData }).then(function (r) {
            return { ok: r.ok, status: r.status, data: r.json() };
        });
    }

    function handleStepUpExpired() {
        window.location.href = config.apiBase + '/admin/verify_identity.php?next=ocr_courses';
    }

    function enforceUppercase(el) {
        if (!el) {
            return;
        }
        el.addEventListener('input', function () {
            var pos = el.selectionStart;
            el.value = el.value.toUpperCase();
            el.setSelectionRange(pos, pos);
        });
    }

    enforceUppercase(input);

    if (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            var keyword = input.value.trim().toUpperCase();
            if (keyword === '') {
                showToast('error', 'ENTER A COURSE/DEGREE KEYWORD FIRST.');
                return;
            }

            addBtn.disabled = true;

            postJson(config.apiBase + '/api/add_ocr_keyword.php', {
                csrf_token: config.csrfToken,
                keyword: keyword,
            })
                .then(function (result) {
                    if (result.status === 403) {
                        handleStepUpExpired();
                        return;
                    }
                    result.data.then(function (data) {
                        addBtn.disabled = false;
                        if (data.status === 'success') {
                            showToast('success', data.message);
                            window.location.reload();
                        } else {
                            showToast('error', data.message);
                        }
                    });
                })
                .catch(function () {
                    addBtn.disabled = false;
                    showToast('error', 'THE KEYWORD COULD NOT BE ADDED. PLEASE TRY AGAIN.');
                });
        });
    }

    if (tbody) {
        tbody.addEventListener('click', function (event) {
            var btn = event.target.closest('.remove-keyword-btn');
            if (!btn) {
                return;
            }

            var keywordId = btn.dataset.keywordId;
            var keyword = btn.dataset.keyword;

            showModal({
                title: 'REMOVE COURSE KEYWORD',
                bodyHtml: '<p class="text-sm text-gray-700 dark:text-gray-300">REMOVE <span class="font-medium text-gray-900 dark:text-white">' + escapeHtml(keyword) + '</span> FROM THE OCR COURSE KEYWORD LIST? FUTURE EXTRACTIONS WILL NO LONGER RECOGNIZE IT.</p>',
                buttons: [
                    { label: 'CANCEL', className: 'rounded-lg px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5' },
                    {
                        label: 'REMOVE',
                        className: 'rounded-lg px-4 py-2 text-sm bg-csc-red-600 hover:bg-csc-red-500 text-white',
                        onClick: function (modalClose) {
                            postJson(config.apiBase + '/api/remove_ocr_keyword.php', {
                                csrf_token: config.csrfToken,
                                id: keywordId,
                            })
                                .then(function (result) {
                                    if (result.status === 403) {
                                        handleStepUpExpired();
                                        return;
                                    }
                                    result.data.then(function (data) {
                                        modalClose();
                                        if (data.status === 'success') {
                                            showToast('success', data.message);
                                            window.location.reload();
                                        } else {
                                            showToast('error', data.message);
                                        }
                                    });
                                })
                                .catch(function () {
                                    modalClose();
                                    showToast('error', 'THE KEYWORD COULD NOT BE REMOVED. PLEASE TRY AGAIN.');
                                });
                        },
                    },
                ],
            });
        });
    }
});
