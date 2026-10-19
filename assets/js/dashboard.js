// Sequenced for every visitor (not just admins), so it lives in its own
// listener rather than inside the admin-only one below.
document.addEventListener('DOMContentLoaded', function () {
    var countEl = document.getElementById('upload-activity-count');
    if (!countEl) {
        return;
    }

    var target = parseInt(countEl.dataset.countTo, 10) || 0;
    var prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // Reveals the line and the shaded area together by growing one shared
    // clip-path rect left-to-right — never by touching the polyline's own
    // dasharray/dashoffset, since that both interacted badly with this
    // chart's vector-effect="non-scaling-stroke" + preserveAspectRatio="none"
    // scaling (a visible gap partway along the line) and isn't needed anyway:
    // clipping exposes the exact same unmodified path the final static graph
    // uses, so there's no way for it to render as anything but one
    // continuous line. Runs once, right after the card's own subtle fade-in.
    function drawChart() {
        var clipRect = document.getElementById('upload-activity-clip-rect');
        if (!clipRect) {
            return;
        }

        var targetWidth = parseFloat(clipRect.dataset.targetWidth) || 0;

        if (prefersReducedMotion) {
            clipRect.setAttribute('width', String(targetWidth));
            return;
        }

        var duration = 1200;
        var startTime = null;

        function step(timestamp) {
            if (startTime === null) {
                startTime = timestamp;
            }
            var progress = Math.min((timestamp - startTime) / duration, 1);
            var eased = 1 - Math.pow(1 - progress, 3);
            clipRect.setAttribute('width', String(targetWidth * eased));
            if (progress < 1) {
                requestAnimationFrame(step);
            }
        }

        requestAnimationFrame(step);
    }

    // Fades the card in first, then — once that quick fade has had time to
    // land — starts the line/area draw, so the two never visually compete.
    function revealChartThenDraw() {
        var chartEl = document.getElementById('upload-activity-chart');
        if (chartEl) {
            chartEl.classList.remove('opacity-0');
        }

        if (prefersReducedMotion) {
            drawChart();
            return;
        }

        setTimeout(drawChart, 300);
    }

    if (prefersReducedMotion) {
        countEl.textContent = formatCount(target);
        revealChartThenDraw();
        return;
    }

    animateCountUp(countEl, target, 1000, revealChartThenDraw);
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
                icon: modalHeaderIcon('trash'),
                iconClassName: 'bg-csc-red-600/10 text-csc-red-600 dark:text-csc-red-400',
                title: 'DELETE UPLOAD',
                subtitle: 'This action permanently deletes the upload and its source PDF. This cannot be undone.',
                bodyHtml: '<p class="rounded-lg bg-gray-50 dark:bg-white/5 px-3 py-2 text-sm text-gray-700 dark:text-gray-300">PERMANENTLY DELETE <span class="font-medium text-gray-900 dark:text-white">' + escapeHtml(label) + '</span> AND ITS SOURCE PDF?</p>',
                buttons: [
                    { label: 'CANCEL', className: MODAL_BTN.secondary },
                    {
                        label: 'DELETE',
                        icon: modalButtonIcon('trash'),
                        className: MODAL_BTN.danger,
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

    // A card whose number follows a year dropdown (CURRENT YEAR RECORDS, HGE APPLICATION AS OF).
    function bindYearCard(yearSelect, yearRecordsNumber, metricQuery) {
        if (!yearSelect || !yearRecordsNumber) {
            return;
        }
        var prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        yearSelect.addEventListener('change', function () {
            var year = yearSelect.value;
            var previousCount = parseInt(yearRecordsNumber.textContent.replace(/,/g, ''), 10) || 0;
            var previousText = yearRecordsNumber.textContent;
            yearRecordsNumber.classList.add('opacity-50');

            fetch(config.apiBase + '/api/get_dashboard_year_records.php?year=' + encodeURIComponent(year) + metricQuery)
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    yearRecordsNumber.classList.remove('opacity-50');
                    if (data.status === 'success') {
                        if (prefersReducedMotion) {
                            yearRecordsNumber.textContent = formatCount(data.count);
                            return;
                        }

                        // A brief scale pulse on top of the count tween (from
                        // the previous value, not 0 — see animateCountUp())
                        // signals "this number just changed" instead of the
                        // flat, instant text swap the card had before.
                        yearRecordsNumber.classList.add('scale-110');
                        setTimeout(function () {
                            yearRecordsNumber.classList.remove('scale-110');
                        }, 250);
                        animateCountUp(yearRecordsNumber, data.count, 500, null, previousCount);
                    } else {
                        yearRecordsNumber.textContent = previousText;
                        showToast('error', data.message || 'THE RECORD COUNT COULD NOT BE LOADED.');
                    }
                })
                .catch(function () {
                    yearRecordsNumber.classList.remove('opacity-50');
                    yearRecordsNumber.textContent = previousText;
                    showToast('error', 'THE RECORD COUNT COULD NOT BE LOADED. PLEASE TRY AGAIN.');
                });
        });
    }

    bindYearCard(document.getElementById('dashboard-year-select'), document.getElementById('dashboard-year-records-number'), '');
    bindYearCard(document.getElementById('dashboard-applied-year-select'), document.getElementById('dashboard-applied-number'), '&metric=applied');
});
