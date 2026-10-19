document.addEventListener('DOMContentLoaded', function () {
    var config = window.HGE_REPORTS;
    if (!config) {
        return;
    }

    function escapeHtmlLocal(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    var MONTH_NAMES = ['JANUARY', 'FEBRUARY', 'MARCH', 'APRIL', 'MAY', 'JUNE', 'JULY', 'AUGUST', 'SEPTEMBER', 'OCTOBER', 'NOVEMBER', 'DECEMBER'];

    // Mirrors admin/reports.php's date('F j, Y \a\t g:i A', ...) formatting,
    // worked out from the raw "YYYY-MM-DD HH:MM:SS" string's own components
    // rather than a JS Date object, so no browser-local-timezone reinterpretation
    // can drift this from the server-rendered initial value.
    function formatUpdatedAt(value) {
        var parts = value.split(/[- :]/);
        var year = parts[0];
        var month = MONTH_NAMES[parseInt(parts[1], 10) - 1];
        var day = parseInt(parts[2], 10);
        var hour = parseInt(parts[3], 10);
        var minute = parts[4];
        var ampm = hour >= 12 ? 'PM' : 'AM';
        var hour12 = hour % 12;
        if (hour12 === 0) {
            hour12 = 12;
        }
        return month + ' ' + day + ', ' + year + ' AT ' + hour12 + ':' + minute + ' ' + ampm;
    }

    // Mirrors admin/reports.php's render_trend_html(): same arrow path,
    // color, and sign logic, built as an HTML string for innerHTML rather
    // than DOM construction since a whole small subtree is replaced at once.
    function buildTrendHtml(change, span) {
        if (change === null || change === undefined) {
            return '';
        }
        var isUp = change >= 0;
        var colorClass = isUp ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400';
        var arrowPath = isUp
            ? 'M2.25 18L9 11.25l4.306 4.306a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941'
            : 'M2.25 6L9 12.75l4.306-4.306a11.95 11.95 0 015.814 5.519l2.74 1.22m0 0l-5.94 2.28m5.94-2.28l-2.28-5.941';
        var sign = isUp ? '+' : '';
        return '<p class="flex items-center gap-1 text-xs ' + colorClass + ' mt-2">' +
            '<svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="' + arrowPath + '" /></svg>' +
            '<span>' + sign + Math.abs(change).toFixed(1) + '% FROM PREVIOUS ' + (span > 1 ? span + ' YEARS' : 'YEAR') + '</span>' +
            '</p>';
    }

    // Reads an element's currently-displayed number back out (stripping the
    // comma grouping formatCount()/toLocaleString() leaves behind) so a
    // later re-count can tween from what's on screen rather than flashing to 0.
    function parseDisplayedNumber(el) {
        return parseInt(String(el.textContent).replace(/,/g, ''), 10) || 0;
    }

    var filingFromSelect = document.getElementById('filing-report-from');
    var filingToSelect = document.getElementById('filing-report-to');
    var filingPrintPeriod = document.getElementById('filing-print-period');
    var filingExportLink = document.getElementById('filing-report-export');
    var filingTbody = document.getElementById('filing-report-tbody');
    var filingTotalOnRecord = document.getElementById('filing-total-on-record');
    var filingTotalApplied = document.getElementById('filing-total-applied');
    var filingOnRecordTrend = document.getElementById('filing-on-record-trend');
    var filingAppliedTrend = document.getElementById('filing-applied-trend');
    var filingUpdatedAtRow = document.getElementById('filing-updated-at-row');
    var filingUpdatedAt = document.getElementById('filing-updated-at');
    var filingSchoolCount = document.getElementById('filing-school-count');
    var filingSchoolSearch = document.getElementById('filing-school-search');
    var filingSchoolSort = document.getElementById('filing-school-sort');
    var filingSchoolStatusFilter = document.getElementById('filing-school-status-filter');
    var tierDistributionEls = {
        'CUM LAUDE': {
            count: document.getElementById('tier-cum-laude'),
            percent: document.getElementById('tier-cum-laude-percent'),
            bar: document.getElementById('tier-cum-laude-bar'),
        },
        'MAGNA CUM LAUDE': {
            count: document.getElementById('tier-magna-cum-laude'),
            percent: document.getElementById('tier-magna-cum-laude-percent'),
            bar: document.getElementById('tier-magna-cum-laude-bar'),
        },
        'SUMMA CUM LAUDE': {
            count: document.getElementById('tier-summa-cum-laude'),
            percent: document.getElementById('tier-summa-cum-laude-percent'),
            bar: document.getElementById('tier-summa-cum-laude-bar'),
        },
    };
    var insightsContainer = document.getElementById('filing-insights');

    // One-time entrance reveal for the tier bars: PHP renders them at
    // width:0 so the first paint is the "before" state, then this sets each
    // to its real target a tick later so the motion-safe:transition-all
    // classes already on those elements (admin/reports.php) animate the
    // draw-in. Later value changes (year-switch, inside renderFilingReport())
    // reuse that same persistent transition for a free "tween to the new
    // value" — no extra code needed there, same as this file's count-up
    // treatment of the stat numbers.
    requestAnimationFrame(function () {
        requestAnimationFrame(function () {
            document.querySelectorAll('.tier-bar-animated').forEach(function (bar) {
                bar.style.width = bar.getAttribute('data-target-width') + '%';
            });
        });
    });

    // ---- trend line chart + filing donut, shared with admin/dashboard.php (see utils.js) ----
    var trendChart = initTrendChart(config);
    var filingDonut = initFilingDonut();
    var reportRequestSeq = 0;

    // Holds the current section's full, unfiltered school list so search/sort/
    // status-filter can re-slice it locally without a round trip — the JSON
    // endpoint already returns every school for the year, unpaginated. Seeded
    // from the server-rendered initial table so filtering works before any
    // year-switch fetch has happened.
    var currentSchools = config.initialSchools || [];

    function schoolRowHtml(school, index) {
        var appliedClass = school.applied > 0
            ? 'bg-csc-navy-600/10 text-csc-navy-600 dark:bg-csc-navy-600/20 dark:text-csc-navy-300'
            : 'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400';
        var rateClass = school.filing_rate > 0
            ? 'bg-green-500/15 text-green-700 dark:bg-green-500/10 dark:text-green-400'
            : 'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400';

        return '<tr class="border-b border-gray-100 dark:border-white/5">' +
            '<td class="px-4 py-3 text-gray-400 dark:text-gray-500">' + (index + 1) + '</td>' +
            '<td class="px-4 py-3">' + escapeHtmlLocal(String(school.school_display).toUpperCase()) + '</td>' +
            '<td class="px-4 py-3">' + school.on_record + '</td>' +
            '<td class="px-4 py-3"><span class="inline-flex items-center justify-center rounded-full px-2.5 py-0.5 text-xs font-semibold ' + appliedClass + '">' + school.applied + '</span></td>' +
            '<td class="px-4 py-3"><span class="inline-flex items-center justify-center rounded-full px-2.5 py-0.5 text-xs font-semibold ' + rateClass + '">' + school.filing_rate + '%</span></td>' +
            '</tr>';
    }

    function renderSchoolTable() {
        var search = filingSchoolSearch ? filingSchoolSearch.value.trim().toUpperCase() : '';
        var statusFilter = filingSchoolStatusFilter ? filingSchoolStatusFilter.value : 'all';
        var sort = filingSchoolSort ? filingSchoolSort.value : 'filing_rate_desc';

        var rows = currentSchools.filter(function (school) {
            if (search && String(school.school_display).toUpperCase().indexOf(search) === -1) {
                return false;
            }
            if (statusFilter === 'filed' && school.applied <= 0) {
                return false;
            }
            if (statusFilter === 'not_filed' && school.applied > 0) {
                return false;
            }
            return true;
        });

        rows.sort(function (a, b) {
            switch (sort) {
                case 'on_record_desc':
                    return b.on_record - a.on_record || a.school_display.localeCompare(b.school_display);
                case 'applied_desc':
                    return b.applied - a.applied || a.school_display.localeCompare(b.school_display);
                case 'school_name_asc':
                    return a.school_display.localeCompare(b.school_display);
                case 'filing_rate_desc':
                default:
                    return b.filing_rate - a.filing_rate || a.school_display.localeCompare(b.school_display);
            }
        });

        if (filingSchoolCount) {
            filingSchoolCount.textContent = rows.length + ' SCHOOL' + (rows.length === 1 ? '' : 'S');
        }

        if (rows.length === 0) {
            filingTbody.innerHTML = '<tr><td colspan="5" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">NO HONOR GRADUATE RECORDS FOUND FOR THIS YEAR.</td></tr>';
            return;
        }

        filingTbody.innerHTML = rows.map(schoolRowHtml).join('');
    }

    function renderInsights(insights) {
        if (!insightsContainer) {
            return;
        }
        if (!insights || !insights.top_filing_rate) {
            insightsContainer.innerHTML = '<p id="filing-insights-empty" class="text-xs text-gray-500 dark:text-gray-400">NO HONOR GRADUATE RECORDS FOUND FOR THIS YEAR.</p>';
            return;
        }

        var topRate = insights.top_filing_rate;
        var mostApplicants = insights.most_applicants;

        insightsContainer.innerHTML =
            '<div id="filing-insight-top-rate">' +
            '<p class="flex items-center gap-1.5 text-[11px] font-bold tracking-wide text-yellow-600 dark:text-yellow-400">' +
            '<svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 18.75h-9m9 0a3 3 0 013 3h-15a3 3 0 013-3m9 0v-3.675A55.378 55.378 0 0012 15c-1.51 0-3.015.05-4.5.15V18.75m9 0a3 3 0 00-3-3h-3a3 3 0 00-3 3m6-9v-1.5m0 1.5a9 9 0 01-9-9V3a.75.75 0 01.75-.75h16.5a.75.75 0 01.75.75v.75a9 9 0 01-9 9z" /></svg>' +
            'TOP FILING RATE</p>' +
            '<p id="filing-insight-top-rate-school" class="text-sm font-semibold text-gray-800 dark:text-white mt-1">' + escapeHtmlLocal(String(topRate.school_display).toUpperCase()) + '</p>' +
            '<p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">' +
            '<span id="filing-insight-top-rate-value" class="text-green-600 dark:text-green-400 font-semibold">' + topRate.filing_rate + '% FILING RATE</span>' +
            ' · <span id="filing-insight-top-rate-detail">' + topRate.applied + ' OF ' + topRate.on_record + ' GRADUATES HAVE FILED</span>' +
            '</p></div>' +
            '<div id="filing-insight-most-applicants">' +
            '<p class="flex items-center gap-1.5 text-[11px] font-bold tracking-wide text-csc-navy-600 dark:text-csc-navy-300">' +
            '<svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" /></svg>' +
            'MOST APPLICANTS</p>' +
            '<p id="filing-insight-most-applicants-school" class="text-sm font-semibold text-gray-800 dark:text-white mt-1">' + escapeHtmlLocal(String(mostApplicants.school_display).toUpperCase()) + '</p>' +
            '<p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">' +
            '<span id="filing-insight-most-applicants-value">' + mostApplicants.applied + ' APPLICATIONS</span>' +
            '</p></div>';
    }

    function renderFilingReport(data) {
        animateCountUp(filingTotalOnRecord, data.report.total_on_record, 1000, null, parseDisplayedNumber(filingTotalOnRecord));
        if (filingTotalApplied) {
            animateCountUp(filingTotalApplied, data.report.total_applied, 1000, null, parseDisplayedNumber(filingTotalApplied));
        }
        if (filingOnRecordTrend) {
            filingOnRecordTrend.innerHTML = buildTrendHtml(data.trends ? data.trends.on_record_change : null, data.span);
        }
        if (filingAppliedTrend) {
            filingAppliedTrend.innerHTML = buildTrendHtml(data.trends ? data.trends.applied_change : null, data.span);
        }

        if (filingUpdatedAtRow && filingUpdatedAt) {
            if (data.updated_at) {
                filingUpdatedAt.textContent = formatUpdatedAt(data.updated_at);
                filingUpdatedAtRow.classList.remove('hidden');
            } else {
                filingUpdatedAtRow.classList.add('hidden');
            }
        }

        updateTierBars(tierDistributionEls, data.tier_breakdown, data.report.total_on_record);

        filingDonut.update(data.report);

        renderInsights(data.insights);

        if (data.trend_series) {
            trendChart.update(data.trend_series, data.trend_monthly, data.to);
        }

        currentSchools = data.report.schools;
        if (filingSchoolSearch) {
            filingSchoolSearch.value = '';
        }
        if (filingSchoolStatusFilter) {
            filingSchoolStatusFilter.value = 'all';
        }
        renderSchoolTable();
    }

    // From/To range: a year can't be picked that would invert the range —
    // the "to" options earlier than "from" and the "from" options later than
    // "to" are disabled, so an invalid combination is never selectable.
    function syncRangeOptions() {
        var from = parseInt(filingFromSelect.value, 10);
        var to = parseInt(filingToSelect.value, 10);
        Array.prototype.forEach.call(filingToSelect.options, function (option) {
            option.disabled = parseInt(option.value, 10) < from;
        });
        Array.prototype.forEach.call(filingFromSelect.options, function (option) {
            option.disabled = parseInt(option.value, 10) > to;
        });
    }

    function loadReportRange() {
        var from = parseInt(filingFromSelect.value, 10);
        var to = parseInt(filingToSelect.value, 10);
        if (from > to) {
            to = from;
            filingToSelect.value = String(to);
        }
        syncRangeOptions();

        var seq = ++reportRequestSeq;
        var query = 'from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to);
        filingExportLink.href = config.apiBase + '/api/export_application_filing_report.php?' + query;
        if (filingPrintPeriod) {
            filingPrintPeriod.textContent = from === to ? String(from) : from + '–' + to;
        }

        fetch(config.apiBase + '/api/get_application_filing_report.php?' + query)
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (seq !== reportRequestSeq) {
                    return;
                }
                if (data.status === 'success') {
                    renderFilingReport(data);
                } else {
                    showToast('error', data.message || 'THE REPORT COULD NOT BE LOADED.');
                }
            })
            .catch(function () {
                if (seq === reportRequestSeq) {
                    showToast('error', 'THE REPORT COULD NOT BE LOADED. PLEASE TRY AGAIN.');
                }
            });
    }

    if (filingFromSelect && filingToSelect) {
        syncRangeOptions();
        filingFromSelect.addEventListener('change', loadReportRange);
        filingToSelect.addEventListener('change', loadReportRange);
    }

    if (filingSchoolSearch) {
        filingSchoolSearch.addEventListener('input', renderSchoolTable);
    }
    if (filingSchoolSort) {
        filingSchoolSort.addEventListener('change', renderSchoolTable);
    }
    if (filingSchoolStatusFilter) {
        filingSchoolStatusFilter.addEventListener('change', renderSchoolTable);
    }

    var filingExportToggle = document.getElementById('filing-export-toggle');
    var filingExportMenu = document.getElementById('filing-export-menu');
    var filingPrintBtn = document.getElementById('filing-print-btn');

    if (filingExportToggle && filingExportMenu) {
        filingExportToggle.addEventListener('click', function (event) {
            event.stopPropagation();
            var isOpen = filingExportToggle.getAttribute('aria-expanded') === 'true';
            filingExportToggle.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
            filingExportMenu.classList.toggle('hidden', isOpen);
        });

        document.addEventListener('click', function (event) {
            if (!filingExportMenu.contains(event.target) && event.target !== filingExportToggle) {
                filingExportToggle.setAttribute('aria-expanded', 'false');
                filingExportMenu.classList.add('hidden');
            }
        });
    }

    if (filingPrintBtn) {
        filingPrintBtn.addEventListener('click', function () {
            if (filingExportMenu) {
                filingExportMenu.classList.add('hidden');
            }
            if (filingExportToggle) {
                filingExportToggle.setAttribute('aria-expanded', 'false');
            }
            window.print();
        });
    }

});
