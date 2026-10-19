// Set inside the first listener below, read from the pill-click handler in
// the second — both fire on the same DOMContentLoaded event, in the order
// they were registered, so this is always assigned before it's read.
var dashboardTrendChart = null;
var dashboardFilingDonut = null;

// Sequenced for every visitor (not just admins), so it lives in its own
// listener rather than inside the admin-only one below.
document.addEventListener('DOMContentLoaded', function () {
    // One-time entrance reveal for the LATIN HONOR DISTRIBUTION tier bars —
    // same draw-in technique as admin/reports.php's identical tier bars:
    // PHP renders each at width:0 so the first paint is the "before" state,
    // then this sets the real target a tick later so the motion-safe
    // transition classes already on the element (admin/dashboard.php)
    // animate the fill.
    requestAnimationFrame(function () {
        requestAnimationFrame(function () {
            document.querySelectorAll('.tier-bar-animated').forEach(function (bar) {
                bar.style.width = bar.getAttribute('data-target-width') + '%';
            });
        });
    });

    // TREND card — shares its renderer with admin/reports.php's identical
    // chart (see initTrendChart() in utils.js), but always shows one
    // year's months (no PER YEAR/PER MONTH toggle), so every point belongs
    // to that same year — highlight the latest month instead of every
    // point, which is what the shared renderer's default comparator would
    // do here.
    dashboardTrendChart = initTrendChart(window.HGE_DASHBOARD || {}, {
        isSelectedPoint: function (p, i, series) {
            return i === series.length - 1;
        },
    });

    // APPLICATION FILING OVERVIEW card — shares its donut renderer with
    // admin/reports.php (see initFilingDonut() in utils.js), scoped to the
    // single selected year instead of a from/to range.
    dashboardFilingDonut = initFilingDonut();
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

    // Refreshes one year-driven number (CURRENT YEAR RECORDS or APPLIED) —
    // shared by both calls the slicer below makes on every click. onSuccess
    // is optional — the plain (non-applied) call uses it to also refresh
    // the TREND card from the same response's trend_monthly.
    function refreshYearCard(yearRecordsNumber, year, metricQuery, onSuccess) {
        if (!yearRecordsNumber) {
            return;
        }
        var prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
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
                    if (onSuccess) {
                        onSuccess(data);
                    }
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
    }

    // Single year slicer driving both the CURRENT YEAR RECORDS and APPLIED
    // cards at once (replaces what used to be two independent dropdowns).
    var yearTrack = document.getElementById('dashboard-year-track');
    if (yearTrack) {
        var yearRecordsNumber = document.getElementById('dashboard-year-records-number');
        var appliedNumber = document.getElementById('dashboard-applied-number');
        var yearRecordsLabel = document.getElementById('dashboard-year-records-label');
        var appliedYearLabel = document.getElementById('dashboard-applied-year-label');
        var activePillClasses = ['bg-csc-navy-600', 'text-white'];
        var inactivePillClasses = ['text-gray-600', 'dark:text-gray-400', 'hover:bg-white', 'dark:hover:bg-white/10'];

        yearTrack.querySelectorAll('.dashboard-year-pill').forEach(function (pill) {
            pill.addEventListener('click', function () {
                if (pill.getAttribute('aria-pressed') === 'true') {
                    return;
                }
                var year = pill.dataset.year;

                yearTrack.querySelectorAll('.dashboard-year-pill').forEach(function (p) {
                    p.classList.remove.apply(p.classList, activePillClasses);
                    p.classList.add.apply(p.classList, inactivePillClasses);
                    p.setAttribute('aria-pressed', 'false');
                });
                pill.classList.remove.apply(pill.classList, inactivePillClasses);
                pill.classList.add.apply(pill.classList, activePillClasses);
                pill.setAttribute('aria-pressed', 'true');

                if (yearRecordsLabel) {
                    yearRecordsLabel.textContent = year;
                }
                if (appliedYearLabel) {
                    appliedYearLabel.textContent = 'HGE APPLICATION AS OF ' + year;
                }

                refreshYearCard(yearRecordsNumber, year, '', function (data) {
                    if (dashboardTrendChart && data.trend_monthly) {
                        dashboardTrendChart.update(null, data.trend_monthly, parseInt(year, 10));
                    }
                    if (dashboardFilingDonut && data.filing_report) {
                        dashboardFilingDonut.update(data.filing_report, data.honor_breakdown);
                    }
                });
                refreshYearCard(appliedNumber, year, '&metric=applied');
            });
        });

        // Shows only 5 pills at a time (w-14 + gap-1 = 60px per step) behind
        // a fixed-width, overflow-hidden viewport, with PREV/NEXT arrows
        // sliding that window one year at a time — only rendered at all
        // (see admin/dashboard.php) when there are more than 5 years to
        // page through.
        var prevBtn = document.getElementById('dashboard-year-prev');
        var nextBtn = document.getElementById('dashboard-year-next');
        if (prevBtn && nextBtn) {
            var pills = Array.prototype.slice.call(yearTrack.querySelectorAll('.dashboard-year-pill'));
            var step = 60;
            var visibleCount = 5;
            var maxOffset = Math.max(0, pills.length - visibleCount);

            var activeIndex = pills.findIndex(function (p) {
                return p.getAttribute('aria-pressed') === 'true';
            });
            var offset = Math.min(Math.max(activeIndex, 0), maxOffset);

            function renderOffset() {
                yearTrack.style.transform = 'translateX(-' + (offset * step) + 'px)';
                prevBtn.disabled = offset <= 0;
                nextBtn.disabled = offset >= maxOffset;
            }

            prevBtn.addEventListener('click', function () {
                offset = Math.max(0, offset - 1);
                renderOffset();
            });
            nextBtn.addEventListener('click', function () {
                offset = Math.min(maxOffset, offset + 1);
                renderOffset();
            });

            renderOffset();
        }
    }
});
