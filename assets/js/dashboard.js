document.addEventListener('DOMContentLoaded', function () {
    var prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function formatCount(value) {
        return Math.round(value).toLocaleString('en-US');
    }

    function animateCountUp(el, target, duration) {
        var startTime = null;

        function step(timestamp) {
            if (startTime === null) {
                startTime = timestamp;
            }
            var progress = Math.min((timestamp - startTime) / duration, 1);
            var eased = 1 - Math.pow(1 - progress, 3);
            el.textContent = formatCount(target * eased);
            if (progress < 1) {
                requestAnimationFrame(step);
            } else {
                el.textContent = formatCount(target);
            }
        }

        requestAnimationFrame(step);
    }

    document.querySelectorAll('.dashboard-stat-number').forEach(function (el, index) {
        var target = parseInt(el.dataset.countTo, 10) || 0;

        if (prefersReducedMotion) {
            el.textContent = formatCount(target);
            el.classList.add('is-visible');
            return;
        }

        setTimeout(function () {
            el.classList.add('is-visible');
            animateCountUp(el, target, 1200);
        }, index * 120);
    });

    var config = window.HGE_DASHBOARD;
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

    document.querySelectorAll('.delete-upload-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var label = btn.dataset.school + ' (' + btn.dataset.year + ')';
            showModal({
                title: 'DELETE UPLOAD',
                bodyHtml: '<p class="text-sm text-gray-700 dark:text-gray-300">PERMANENTLY DELETE <span class="text-gray-900 dark:text-white font-medium">' + escapeHtml(label) + '</span> AND ITS SOURCE PDF? THIS CANNOT BE UNDONE.</p>',
                buttons: [
                    { label: 'CANCEL', className: 'rounded-lg px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5' },
                    {
                        label: 'DELETE',
                        className: 'rounded-lg px-4 py-2 text-sm bg-red-600 hover:bg-red-500 text-white',
                        onClick: function (close) {
                            close();
                            postJson(config.apiBase + '/api/delete_upload.php', {
                                csrf_token: config.csrfToken,
                                upload_id: btn.dataset.uploadId,
                            })
                                .then(function (data) {
                                    if (data.status === 'success') {
                                        showToast('success', data.message);
                                        window.location.reload();
                                    } else {
                                        showToast('error', data.message);
                                    }
                                })
                                .catch(function () {
                                    showToast('error', 'THE UPLOAD COULD NOT BE DELETED. PLEASE TRY AGAIN.');
                                });
                        },
                    },
                ],
            });
        });
    });
});
