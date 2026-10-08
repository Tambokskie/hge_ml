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

    // Same geometry admin/reports.php computes in PHP for the initial render —
    // kept identical so the AJAX year-switch redraw matches pixel-for-pixel.
    var DONUT_RADIUS = 50;
    var DONUT_CIRCUMFERENCE = 2 * Math.PI * DONUT_RADIUS;

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

    // animateCountUp() rounds to whole numbers, so the filing-rate percentage
    // (one decimal, e.g. 0.8%) gets its own tween from what's on screen to the
    // new value. Honors prefers-reduced-motion and a newer call supersedes it.
    function animateRate(el, target, duration) {
        var from = parseFloat(el.textContent) || 0;
        var token = el.__rateToken = (el.__rateToken || 0) + 1;

        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            el.textContent = target + '%';
            return;
        }

        var startTime = null;
        function step(timestamp) {
            if (el.__rateToken !== token) {
                return;
            }
            if (startTime === null) {
                startTime = timestamp;
            }
            var progress = Math.min((timestamp - startTime) / duration, 1);
            var eased = 1 - Math.pow(1 - progress, 3);
            if (progress < 1) {
                el.textContent = (Math.round((from + (target - from) * eased) * 10) / 10) + '%';
                requestAnimationFrame(step);
            } else {
                el.textContent = target + '%';
            }
        }
        requestAnimationFrame(step);
    }

    var filingFromSelect = document.getElementById('filing-report-from');
    var filingToSelect = document.getElementById('filing-report-to');
    var filingPrintPeriod = document.getElementById('filing-print-period');
    var filingExportLink = document.getElementById('filing-report-export');
    var filingTbody = document.getElementById('filing-report-tbody');
    var filingTotalOnRecord = document.getElementById('filing-total-on-record');
    var filingTotalApplied = document.getElementById('filing-total-applied');
    var filingRate = document.getElementById('filing-rate');
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
    var donutAppliedArc = document.getElementById('filing-donut-applied-arc');
    var donutLegend = document.getElementById('filing-donut-legend');
    var insightsContainer = document.getElementById('filing-insights');

    // One-time entrance reveal for the tier bars and donut arc: PHP renders
    // them at width:0 / a fully-hidden stroke-dashoffset so the first paint
    // is the "before" state, then this sets each to its real target a tick
    // later so the motion-safe:transition-all classes already on those
    // elements (admin/reports.php) animate the draw-in. Later value changes
    // (year-switch, inside renderFilingReport()) reuse that same persistent
    // transition for a free "tween to the new value" — no extra code needed
    // there, same as this file's count-up treatment of the stat numbers.
    requestAnimationFrame(function () {
        requestAnimationFrame(function () {
            document.querySelectorAll('.tier-bar-animated').forEach(function (bar) {
                bar.style.width = bar.getAttribute('data-target-width') + '%';
            });
            if (donutAppliedArc) {
                donutAppliedArc.setAttribute('stroke-dashoffset', donutAppliedArc.getAttribute('data-target-offset'));
            }
        });
    });

    // ---- trend line chart, per year or per month (hand-rolled SVG, no charting library) ----
    // Two lines on ONE shared axis — graduates on record and Honor Graduate
    // Eligible filings. Applicants are a subset of graduates, so the applied line
    // must never be drawn above the graduated one; separate scales would let it.
    var trendContainer = document.getElementById('filing-trend-chart');
    var trendSeries = config.initialTrend || [];
    var trendMonthly = config.initialTrendMonthly || { months: [], undated: { on_record: 0, applied: 0 } };
    var trendMode = 'year';
    var trendSelectedYear = config.initialTo;
    var trendNote = document.getElementById('filing-trend-note');
    var TREND_MONTH_ABBR = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];

    // The points currently charted, whichever grouping is selected. "label" is
    // the x-axis text, "title" the tooltip heading, "year" what the selected-
    // year highlight compares against.
    function trendPoints() {
        if (trendMode === 'month') {
            var multiYear = trendMonthly.months.length > 12;
            return trendMonthly.months.map(function (p) {
                return {
                    year: p.year,
                    label: TREND_MONTH_ABBR[p.month - 1] + (multiYear ? " '" + String(p.year).slice(-2) : ''),
                    title: MONTH_NAMES[p.month - 1] + ' ' + p.year,
                    on_record: p.on_record,
                    applied: p.applied
                };
            });
        }
        return trendSeries.map(function (p) {
            return { year: p.year, label: String(p.year), title: String(p.year), on_record: p.on_record, applied: p.applied };
        });
    }

    function updateTrendNote() {
        if (!trendNote) {
            return;
        }
        var undated = trendMonthly.undated || { on_record: 0, applied: 0 };
        if (trendMode === 'month' && undated.on_record > 0) {
            trendNote.textContent = undated.on_record.toLocaleString('en-US') + ' GRADUATE' + (undated.on_record === 1 ? '' : 'S') +
                ' (' + undated.applied.toLocaleString('en-US') + ' APPLIED) HAVE NO GRADUATION DATE ON RECORD AND ARE NOT SHOWN IN THE MONTHS.';
            trendNote.classList.remove('hidden');
        } else {
            trendNote.classList.add('hidden');
        }
    }
    var reportRequestSeq = 0;

    function niceStep(rawStep) {
        var pow = Math.pow(10, Math.floor(Math.log10(rawStep)));
        var n = rawStep / pow;
        var nice = n <= 1 ? 1 : (n <= 2 ? 2 : (n <= 2.5 ? 2.5 : (n <= 5 ? 5 : 10)));
        return nice * pow;
    }

    function niceAxisMax(value) {
        if (value <= 0) {
            return 4;
        }
        var step = Math.max(niceStep(value / 4), 1);
        return step * 4;
    }

    // Catmull-Rom spline through the points, expressed as cubic Beziers. Each
    // segment's control points are clamped between its two end points' heights
    // (and the plot), so the curve never overshoots a data point — it can't dip
    // below the baseline, and a spike can't push one line over the other.
    function smoothPath(points, minY, maxY) {
        if (points.length < 2) {
            return points.length ? 'M' + points[0].x + ',' + points[0].y : '';
        }
        var d = 'M' + points[0].x.toFixed(1) + ',' + points[0].y.toFixed(1);
        for (var i = 0; i < points.length - 1; i++) {
            var p0 = points[i - 1] || points[i];
            var p1 = points[i];
            var p2 = points[i + 1];
            var p3 = points[i + 2] || p2;
            var segLo = Math.max(minY, Math.min(p1.y, p2.y));
            var segHi = Math.min(maxY, Math.max(p1.y, p2.y));
            var clampY = function (y) {
                return Math.min(segHi, Math.max(segLo, y));
            };
            var c1x = p1.x + (p2.x - p0.x) / 6;
            var c1y = clampY(p1.y + (p2.y - p0.y) / 6);
            var c2x = p2.x - (p3.x - p1.x) / 6;
            var c2y = clampY(p2.y - (p3.y - p1.y) / 6);
            d += ' C' + c1x.toFixed(1) + ',' + c1y.toFixed(1) + ' ' + c2x.toFixed(1) + ',' + c2y.toFixed(1) + ' ' + p2.x.toFixed(1) + ',' + p2.y.toFixed(1);
        }
        return d;
    }

    function renderTrendChart(animate) {
        var series = trendPoints();
        if (!trendContainer || series.length === 0) {
            return;
        }
        var width = trendContainer.clientWidth;
        var height = trendContainer.clientHeight;
        if (width < 120) {
            return;
        }

        var m = { top: 14, right: 16, bottom: 28, left: 48 };
        var plotW = width - m.left - m.right;
        var plotH = height - m.top - m.bottom;
        var inset = 24;
        var maxOn = niceAxisMax(Math.max.apply(null, series.map(function (p) { return Math.max(p.on_record, p.applied); })));
        var maxAp = maxOn;
        var baseY = m.top + plotH;

        function xAt(i) {
            return m.left + inset + (series.length === 1 ? (plotW - 2 * inset) / 2 : i * (plotW - 2 * inset) / (series.length - 1));
        }
        function yAt(value, max) {
            return baseY - (value / max) * plotH;
        }

        var onPoints = series.map(function (p, i) { return { x: xAt(i), y: yAt(p.on_record, maxOn) }; });
        var apPoints = series.map(function (p, i) { return { x: xAt(i), y: yAt(p.applied, maxAp) }; });
        var onLine = smoothPath(onPoints, m.top, baseY);
        var apLine = smoothPath(apPoints, m.top, baseY);
        var lastX = onPoints[onPoints.length - 1].x;
        var firstX = onPoints[0].x;

        var svg = '<svg width="' + width + '" height="' + height + '" viewBox="0 0 ' + width + ' ' + height + '" class="block overflow-visible">';
        svg += '<defs>' +
            '<linearGradient id="trend-grad-on" x1="0" y1="0" x2="0" y2="1" class="text-csc-navy-600 dark:text-csc-navy-300"><stop offset="0%" stop-color="currentColor" stop-opacity="0.28"/><stop offset="100%" stop-color="currentColor" stop-opacity="0"/></linearGradient>' +
            '<linearGradient id="trend-grad-ap" x1="0" y1="0" x2="0" y2="1" class="text-emerald-500"><stop offset="0%" stop-color="currentColor" stop-opacity="0.22"/><stop offset="100%" stop-color="currentColor" stop-opacity="0"/></linearGradient>' +
            '</defs>';

        for (var t = 0; t <= 4; t++) {
            var gy = m.top + (plotH * t / 4);
            var onVal = Math.round(maxOn * (4 - t) / 4);
            svg += '<line x1="' + m.left + '" y1="' + gy + '" x2="' + (width - m.right) + '" y2="' + gy + '" stroke="currentColor" stroke-width="1" class="text-gray-200 dark:text-white/10"/>';
            svg += '<text x="' + (m.left - 8) + '" y="' + (gy + 4) + '" text-anchor="end" font-size="11" fill="currentColor" class="text-gray-500 dark:text-gray-400">' + onVal.toLocaleString('en-US') + '</text>';
        }

        // Many points (months across several years) can't all carry a label:
        // show every step-th one so neighbouring labels never overlap.
        var spacing = series.length > 1 ? (plotW - 2 * inset) / (series.length - 1) : plotW;
        var labelStep = Math.max(1, Math.ceil(46 / spacing));
        series.forEach(function (p, i) {
            if (i % labelStep !== 0) {
                return;
            }
            var isSelected = p.year === trendSelectedYear;
            svg += '<text x="' + xAt(i) + '" y="' + (height - 8) + '" text-anchor="middle" font-size="11" fill="currentColor" class="' +
                (isSelected ? 'text-csc-navy-600 dark:text-white font-bold' : 'text-gray-500 dark:text-gray-400') + '">' + p.label + '</text>';
        });

        svg += '<g class="text-csc-navy-600 dark:text-csc-navy-300"><path data-trend-area d="' + onLine + ' L' + lastX.toFixed(1) + ',' + baseY + ' L' + firstX.toFixed(1) + ',' + baseY + ' Z" fill="url(#trend-grad-on)" stroke="none"/>' +
            '<path data-trend-line d="' + onLine + '" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"/></g>';
        svg += '<g class="text-emerald-500"><path data-trend-area d="' + apLine + ' L' + lastX.toFixed(1) + ',' + baseY + ' L' + firstX.toFixed(1) + ',' + baseY + ' Z" fill="url(#trend-grad-ap)" stroke="none"/>' +
            '<path data-trend-line d="' + apLine + '" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"/></g>';

        var dense = spacing < 14;
        series.forEach(function (p, i) {
            var r = dense ? 1.75 : (p.year === trendSelectedYear && trendMode === 'year' ? 4.5 : 3);
            svg += '<circle cx="' + onPoints[i].x + '" cy="' + onPoints[i].y + '" r="' + r + '" fill="currentColor" stroke="white" stroke-width="1.5" class="text-csc-navy-600 dark:text-csc-navy-300"/>';
            svg += '<circle cx="' + apPoints[i].x + '" cy="' + apPoints[i].y + '" r="' + r + '" fill="currentColor" stroke="white" stroke-width="1.5" class="text-emerald-500"/>';
        });

        svg += '<line id="trend-hover-line" x1="0" y1="' + m.top + '" x2="0" y2="' + baseY + '" stroke="currentColor" stroke-width="1" stroke-dasharray="4 4" class="text-csc-navy-600 dark:text-csc-navy-300" style="display:none"/>' +
            '<circle id="trend-hover-on" r="6" fill="white" stroke="currentColor" stroke-width="2.5" class="text-csc-navy-600 dark:text-csc-navy-300" style="display:none"/>' +
            '<circle id="trend-hover-ap" r="6" fill="white" stroke="currentColor" stroke-width="2.5" class="text-emerald-500" style="display:none"/>' +
            '<rect id="trend-hit" x="' + m.left + '" y="' + m.top + '" width="' + plotW + '" height="' + plotH + '" fill="transparent"/>';
        svg += '</svg>';
        svg += '<div id="trend-tooltip" class="pointer-events-none absolute z-10 hidden rounded-lg bg-gray-900 px-2.5 py-1.5 text-xs text-white shadow-lg whitespace-nowrap dark:bg-white dark:text-gray-900"></div>';

        trendContainer.innerHTML = svg;

        var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (animate && !reduceMotion) {
            trendContainer.querySelectorAll('[data-trend-line]').forEach(function (path) {
                var len = path.getTotalLength();
                path.style.strokeDasharray = len;
                path.style.strokeDashoffset = len;
                path.getBoundingClientRect();
                path.style.transition = 'stroke-dashoffset 1200ms ease-out';
                path.style.strokeDashoffset = 0;
            });
            trendContainer.querySelectorAll('[data-trend-area]').forEach(function (path) {
                path.style.opacity = 0;
                path.getBoundingClientRect();
                path.style.transition = 'opacity 1200ms ease-out';
                path.style.opacity = 1;
            });
        }

        var hit = document.getElementById('trend-hit');
        var hoverLine = document.getElementById('trend-hover-line');
        var hoverOn = document.getElementById('trend-hover-on');
        var hoverAp = document.getElementById('trend-hover-ap');
        var tooltip = document.getElementById('trend-tooltip');

        function hideHover() {
            hoverLine.style.display = 'none';
            hoverOn.style.display = 'none';
            hoverAp.style.display = 'none';
            tooltip.classList.add('hidden');
        }

        function showHover(event) {
            var rect = trendContainer.getBoundingClientRect();
            var px = event.clientX - rect.left;
            var nearest = 0;
            var best = Infinity;
            series.forEach(function (p, i) {
                var dist = Math.abs(xAt(i) - px);
                if (dist < best) {
                    best = dist;
                    nearest = i;
                }
            });
            var point = series[nearest];
            var x = xAt(nearest);

            hoverLine.setAttribute('x1', x);
            hoverLine.setAttribute('x2', x);
            hoverLine.style.display = '';
            hoverOn.setAttribute('cx', x);
            hoverOn.setAttribute('cy', onPoints[nearest].y);
            hoverOn.style.display = '';
            hoverAp.setAttribute('cx', x);
            hoverAp.setAttribute('cy', apPoints[nearest].y);
            hoverAp.style.display = '';

            tooltip.innerHTML = '<div class="font-semibold mb-0.5">' + point.title + '</div>' +
                '<div class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-csc-navy-300"></span>GRADUATED: <b>' + point.on_record.toLocaleString('en-US') + '</b></div>' +
                '<div class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-emerald-400"></span>APPLIED FOR HGE: <b>' + point.applied.toLocaleString('en-US') + '</b></div>';
            tooltip.classList.remove('hidden');
            var tipW = tooltip.offsetWidth;
            var left = x + 14;
            if (left + tipW > width - 4) {
                left = x - tipW - 14;
            }
            tooltip.style.left = Math.max(4, left) + 'px';
            tooltip.style.top = Math.max(0, Math.min(onPoints[nearest].y, apPoints[nearest].y) - 20) + 'px';
        }

        hit.addEventListener('pointermove', showHover);
        hit.addEventListener('pointerdown', showHover);
        hit.addEventListener('pointerleave', hideHover);
    }

    document.querySelectorAll('[data-trend-mode]').forEach(function (button) {
        button.addEventListener('click', function () {
            trendMode = button.getAttribute('data-trend-mode') === 'month' ? 'month' : 'year';
            document.querySelectorAll('[data-trend-mode]').forEach(function (other) {
                other.setAttribute('aria-pressed', other === button ? 'true' : 'false');
            });
            updateTrendNote();
            renderTrendChart(true);
        });
    });

    if (trendContainer) {
        renderTrendChart(true);

        var trendResizeFrame = null;
        var trendLastWidth = trendContainer.clientWidth;
        new ResizeObserver(function () {
            if (trendContainer.clientWidth === trendLastWidth) {
                return;
            }
            trendLastWidth = trendContainer.clientWidth;
            cancelAnimationFrame(trendResizeFrame);
            trendResizeFrame = requestAnimationFrame(function () { renderTrendChart(false); });
        }).observe(trendContainer);
    }

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
        animateCountUp(filingTotalApplied, data.report.total_applied, 1000, null, parseDisplayedNumber(filingTotalApplied));
        animateRate(filingRate, data.report.filing_rate, 1000);

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

        var tierBreakdown = data.tier_breakdown || {};
        var totalOnRecord = data.report.total_on_record;
        Object.keys(tierDistributionEls).forEach(function (tierName) {
            var els = tierDistributionEls[tierName];
            var count = tierBreakdown[tierName] || 0;
            var percent = totalOnRecord > 0 ? Math.round((count / totalOnRecord) * 1000) / 10 : 0;
            if (els.count) {
                animateCountUp(els.count, count, 800, null, parseDisplayedNumber(els.count));
            }
            if (els.percent) {
                els.percent.textContent = '(' + percent + '%)';
            }
            if (els.bar) {
                els.bar.style.width = percent + '%';
            }
        });

        if (donutAppliedArc) {
            var appliedFraction = totalOnRecord > 0 ? data.report.total_applied / totalOnRecord : 0;
            var appliedLength = DONUT_CIRCUMFERENCE * appliedFraction;
            donutAppliedArc.setAttribute('stroke-dasharray', DONUT_CIRCUMFERENCE.toFixed(2));
            donutAppliedArc.setAttribute('stroke-dashoffset', (DONUT_CIRCUMFERENCE - appliedLength).toFixed(2));
        }
        if (donutLegend) {
            var notYetApplied = totalOnRecord - data.report.total_applied;
            var notYetAppliedPercent = totalOnRecord > 0 ? Math.round((notYetApplied / totalOnRecord) * 1000) / 10 : 0;
            donutLegend.innerHTML =
                '<div class="flex items-center justify-between"><span class="flex items-center gap-1.5 text-gray-600 dark:text-gray-400"><span class="h-2 w-2 rounded-full bg-gray-400"></span>ON RECORD</span><span class="text-gray-700 dark:text-gray-300">' + Number(totalOnRecord).toLocaleString('en-US') + ' (100%)</span></div>' +
                '<div class="flex items-center justify-between"><span class="flex items-center gap-1.5 text-gray-600 dark:text-gray-400"><span class="h-2 w-2 rounded-full bg-csc-navy-600 dark:bg-csc-navy-300"></span>APPLIED</span><span class="text-gray-700 dark:text-gray-300">' + Number(data.report.total_applied).toLocaleString('en-US') + ' (' + data.report.filing_rate + '%)</span></div>' +
                '<div class="flex items-center justify-between"><span class="flex items-center gap-1.5 text-gray-600 dark:text-gray-400"><span class="h-2 w-2 rounded-full bg-sky-200 dark:bg-sky-500/30"></span>NOT YET APPLIED</span><span class="text-gray-700 dark:text-gray-300">' + Number(notYetApplied).toLocaleString('en-US') + ' (' + notYetAppliedPercent + '%)</span></div>';
        }

        renderInsights(data.insights);

        if (data.trend_series) {
            trendSeries = data.trend_series;
            if (data.trend_monthly) {
                trendMonthly = data.trend_monthly;
            }
            trendSelectedYear = data.to;
            updateTrendNote();
            renderTrendChart(true);
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
