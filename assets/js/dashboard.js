// Sequenced for every visitor (not just admins), so it lives in its own
// listener rather than inside the admin-only one below.
document.addEventListener('DOMContentLoaded', function () {
    var countEl = document.getElementById('upload-activity-count');
    if (!countEl) {
        return;
    }

    var target = parseInt(countEl.dataset.countTo, 10) || 0;

    // Draws the line left-to-right (stroke-dashoffset from its full length down
    // to 0, in the same left-to-right point order the polyline was built in)
    // while the clip-path rect behind the shaded area grows in step, so the
    // fill is only ever revealed up to wherever the line currently is. Runs
    // once, right after the PDF count finishes — never loops.
    function drawChartLine() {
        var line = document.getElementById('upload-activity-line');
        var clipRect = document.getElementById('upload-activity-clip-rect');
        if (!line) {
            return;
        }

        var lineLength = parseFloat(line.dataset.length) || 0;
        var targetWidth = clipRect ? parseFloat(clipRect.dataset.targetWidth) || 0 : 0;

        function setProgress(eased) {
            line.style.strokeDashoffset = String(lineLength * (1 - eased));
            if (clipRect) {
                clipRect.setAttribute('width', String(targetWidth * eased));
            }
        }

        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            setProgress(1);
            return;
        }

        var duration = 1200;
        var startTime = null;

        function step(timestamp) {
            if (startTime === null) {
                startTime = timestamp;
            }
            var progress = Math.min((timestamp - startTime) / duration, 1);
            setProgress(1 - Math.pow(1 - progress, 3));
            if (progress < 1) {
                requestAnimationFrame(step);
            }
        }

        requestAnimationFrame(step);
    }

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        countEl.textContent = formatCount(target);
        drawChartLine();
        return;
    }

    animateCountUp(countEl, target, 1000, drawChartLine);
});

document.addEventListener('DOMContentLoaded', function () {
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
                icon: '<svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>',
                iconClassName: 'bg-csc-red-600/10 text-csc-red-600 dark:text-csc-red-400',
                title: 'DELETE UPLOAD',
                bodyHtml: '<p>PERMANENTLY DELETE <span class="font-medium text-gray-900 dark:text-white">' + escapeHtml(label) + '</span> AND ITS SOURCE PDF? THIS CANNOT BE UNDONE.</p>',
                buttons: [
                    { label: 'CANCEL', className: 'flex-1 rounded-full border border-gray-300 dark:border-white/15 px-4 py-2.5 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 transition-colors' },
                    {
                        label: 'DELETE',
                        className: 'flex-1 rounded-full px-4 py-2.5 text-sm font-medium bg-csc-red-600 hover:bg-csc-red-500 text-white transition-colors',
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
