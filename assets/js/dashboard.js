// Set inside the first listener below, read from the pill-click handler in
// the second — both fire on the same DOMContentLoaded event, in the order
// they were registered, so this is always assigned before it's read.
var dashboardTrendChart = null;
var dashboardFilingDonut = null;

// Sequenced for every visitor (not just admins), so it lives in its own
// listener rather than inside the admin-only one below.
document.addEventListener('DOMContentLoaded', function () {
    // One-time entrance reveal for the LATIN HONOR DISTRIBUTION tier bars:
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

    // TREND card — rendered by the shared initTrendChart() in utils.js (kept
    // generic enough for multiple callers even though this page is the only
    // one left using it), always showing one year's months (no PER
    // YEAR/PER MONTH toggle), so every point belongs to that same year —
    // highlight the latest month instead of every point, which is what the
    // shared renderer's default comparator would do here. Three series:
    // GRADUATED (navy), PROCESSING (orange, filed-by-month, exclusive of
    // anything since approved) and APPROVED (green, by approval month) —
    // see includes/reports.php:get_filing_trend_monthly().
    dashboardTrendChart = initTrendChart(window.HGE_DASHBOARD || {}, {
        isSelectedPoint: function (p, i, series) {
            return i === series.length - 1;
        },
        series: [
            { key: 'on_record', textClass: 'text-csc-navy-600 dark:text-csc-navy-300', dotClass: 'bg-csc-navy-300', gradOpacity: '0.28', label: 'GRADUATED' },
            { key: 'processing', textClass: 'text-orange-500', dotClass: 'bg-orange-400', gradOpacity: '0.20', label: 'PROCESSING' },
            { key: 'approved', textClass: 'text-emerald-500', dotClass: 'bg-emerald-400', gradOpacity: '0.22', label: 'APPROVED' },
        ],
        monthNote: 'GRADUATED IS PLOTTED BY GRADUATION MONTH; PROCESSING IS PLOTTED BY THE MONTH THE APPLICATION WAS FILED; APPROVED IS PLOTTED BY THE MONTH IT WAS APPROVED.',
    });

    // APPLICATION FILING OVERVIEW card — rendered by the shared
    // initFilingDonut() in utils.js, scoped to the single selected year.
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

    // SCHOOL FILING SUMMARY table — no search/sort/filter controls, just a
    // fixed top-10 view behind a SHOW MORE/SHOW LESS toggle, since the
    // per-school rows already arrive pre-sorted by filing rate from the
    // server (both the initial PHP render and this JSON API).
    var schoolTbody = document.getElementById('dashboard-school-tbody');
    var schoolCount = document.getElementById('dashboard-school-count');
    var schoolToggleRow = document.getElementById('dashboard-school-toggle-row');
    var schoolToggleBtn = document.getElementById('dashboard-school-toggle');

    function schoolRowHtml(school, index) {
        var appliedClass = school.applied > 0
            ? 'bg-csc-navy-600/10 text-csc-navy-600 dark:bg-csc-navy-600/20 dark:text-csc-navy-300'
            : 'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400';
        var approvedClass = school.approved > 0
            ? 'bg-emerald-500/15 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400'
            : 'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400';
        var rateClass = school.filing_rate > 0
            ? 'bg-green-500/15 text-green-700 dark:bg-green-500/10 dark:text-green-400'
            : 'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400';
        var approvalRateClass = school.approval_rate > 0
            ? 'bg-emerald-500/15 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400'
            : 'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400';
        var hiddenClass = index >= 10 ? ' dashboard-school-extra-row hidden' : '';

        return '<tr class="dashboard-school-row border-b border-gray-100 dark:border-white/5' + hiddenClass + '">' +
            '<td class="px-4 py-3 text-gray-400 dark:text-gray-500">' + (index + 1) + '</td>' +
            '<td class="px-4 py-3"><a href="' + config.apiBase + '/admin/school_records.php?school_id=' + encodeURIComponent(school.school_id) + '" class="inline-block transition-all duration-200 hover:translate-x-1 hover:text-csc-navy-600 dark:hover:text-csc-navy-300">' + escapeHtml(String(school.school_display).toUpperCase()) + '</a></td>' +
            '<td class="px-4 py-3">' + school.on_record + '</td>' +
            '<td class="px-4 py-3"><span class="inline-flex items-center justify-center rounded-full px-2.5 py-0.5 text-xs font-semibold ' + appliedClass + '">' + school.applied + '</span></td>' +
            '<td class="px-4 py-3"><span class="inline-flex items-center justify-center rounded-full px-2.5 py-0.5 text-xs font-semibold ' + approvedClass + '">' + school.approved + '</span></td>' +
            '<td class="px-4 py-3"><span class="inline-flex items-center justify-center rounded-full px-2.5 py-0.5 text-xs font-semibold ' + rateClass + '">' + school.filing_rate + '%</span></td>' +
            '<td class="px-4 py-3"><span class="inline-flex items-center justify-center rounded-full px-2.5 py-0.5 text-xs font-semibold ' + approvalRateClass + '">' + school.approval_rate + '%</span></td>' +
            '</tr>';
    }

    // Re-renders the whole table for a year switch and resets the toggle
    // back to its collapsed SHOW MORE state — carrying an expanded state
    // across a year switch would show a stale row count's worth of schools
    // expanded against the new year's actual list.
    function renderSchoolTable(schools) {
        if (!schoolTbody) {
            return;
        }
        if (schoolCount) {
            schoolCount.textContent = schools.length + ' SCHOOL' + (schools.length === 1 ? '' : 'S');
        }
        schoolTbody.innerHTML = schools.length === 0
            ? '<tr><td colspan="7" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">NO HONOR GRADUATE RECORDS FOUND FOR THIS YEAR.</td></tr>'
            : schools.map(schoolRowHtml).join('');

        if (schoolToggleRow) {
            schoolToggleRow.classList.toggle('hidden', schools.length <= 10);
        }
        if (schoolToggleBtn) {
            schoolToggleBtn.dataset.expanded = 'false';
            schoolToggleBtn.textContent = 'SHOW MORE';
        }
    }

    // SHOW MORE/SHOW LESS staggers each extra row's reveal/hide with a short
    // fade+slide rather than snapping all of them in or out at once. Plain
    // inline styles (not Tailwind classes) since the timing is per-row via
    // transitionDelay, which a static utility class can't express; "hidden"
    // still does the actual show/hide — the transition only covers the
    // moment just before/after that, so a row never sits invisible-but-still
    // taking up table layout space for longer than its own transition.
    if (schoolToggleBtn) {
        schoolToggleBtn.addEventListener('click', function () {
            var expanded = schoolToggleBtn.dataset.expanded === 'true';
            var rows = Array.prototype.slice.call(document.querySelectorAll('.dashboard-school-extra-row'));
            var prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            var staggerStep = 30;
            var duration = 250;

            if (expanded) {
                // SHOW LESS: fade/slide each row out, then flip it back to
                // "hidden" once its own transition finishes.
                rows.forEach(function (row, index) {
                    if (prefersReducedMotion) {
                        row.classList.add('hidden');
                        return;
                    }
                    var delay = index * staggerStep;
                    row.style.transition = 'opacity ' + duration + 'ms ease-in, transform ' + duration + 'ms ease-in';
                    row.style.transitionDelay = delay + 'ms';
                    row.style.opacity = '0';
                    row.style.transform = 'translateY(-6px)';
                    setTimeout(function () {
                        row.classList.add('hidden');
                        row.style.transition = '';
                        row.style.transitionDelay = '';
                        row.style.opacity = '';
                        row.style.transform = '';
                    }, duration + delay);
                });
            } else {
                // SHOW MORE: un-hide each row starting from its faded/raised
                // "from" state, forcing a reflow so that state actually
                // paints before the transition to its resting position.
                rows.forEach(function (row, index) {
                    row.classList.remove('hidden');
                    if (prefersReducedMotion) {
                        return;
                    }
                    var delay = index * staggerStep;
                    row.style.transition = 'none';
                    row.style.opacity = '0';
                    row.style.transform = 'translateY(-6px)';
                    void row.offsetWidth;
                    row.style.transition = 'opacity ' + duration + 'ms ease-out, transform ' + duration + 'ms ease-out';
                    row.style.transitionDelay = delay + 'ms';
                    row.style.opacity = '1';
                    row.style.transform = 'translateY(0)';
                    setTimeout(function () {
                        row.style.transition = '';
                        row.style.transitionDelay = '';
                        row.style.opacity = '';
                        row.style.transform = '';
                    }, duration + delay + 50);
                });
            }

            schoolToggleBtn.dataset.expanded = expanded ? 'false' : 'true';
            schoolToggleBtn.textContent = expanded ? 'SHOW MORE' : 'SHOW LESS';
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
        var approvedNumber = document.getElementById('dashboard-approved-number');
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

                refreshYearCard(yearRecordsNumber, year, '', function (data) {
                    if (dashboardTrendChart && data.trend_monthly) {
                        dashboardTrendChart.update(null, data.trend_monthly, parseInt(year, 10));
                    }
                    if (dashboardFilingDonut && data.filing_report) {
                        dashboardFilingDonut.update(data.filing_report, data.honor_breakdown);
                    }
                    if (data.schools) {
                        renderSchoolTable(data.schools);
                    }
                    // Reuses the same (non-applied) call's filing_report
                    // rather than a dedicated metric=approved request — the
                    // number is already in that response.
                    if (approvedNumber && data.filing_report) {
                        var previousApproved = parseInt(String(approvedNumber.textContent).replace(/,/g, ''), 10) || 0;
                        animateCountUp(approvedNumber, data.filing_report.total_approved, 500, null, previousApproved);
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
