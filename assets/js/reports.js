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

    var filingYearSelect = document.getElementById('filing-report-year');
    var filingExportLink = document.getElementById('filing-report-export');
    var filingTbody = document.getElementById('filing-report-tbody');
    var filingTotalOnRecord = document.getElementById('filing-total-on-record');
    var filingTotalApplied = document.getElementById('filing-total-applied');
    var filingRate = document.getElementById('filing-rate');
    var tierCumLaude = document.getElementById('tier-cum-laude');
    var tierMagnaCumLaude = document.getElementById('tier-magna-cum-laude');
    var tierSummaCumLaude = document.getElementById('tier-summa-cum-laude');

    function renderFilingReport(data) {
        filingTotalOnRecord.textContent = data.report.total_on_record;
        filingTotalApplied.textContent = data.report.total_applied;
        filingRate.textContent = data.report.filing_rate + '%';
        tierCumLaude.textContent = data.tier_breakdown['CUM LAUDE'];
        tierMagnaCumLaude.textContent = data.tier_breakdown['MAGNA CUM LAUDE'];
        tierSummaCumLaude.textContent = data.tier_breakdown['SUMMA CUM LAUDE'];

        if (data.report.schools.length === 0) {
            filingTbody.innerHTML = '<tr><td colspan="4" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">NO HONOR GRADUATE RECORDS FOUND FOR THIS YEAR.</td></tr>';
            return;
        }

        filingTbody.innerHTML = data.report.schools.map(function (school) {
            return '<tr class="border-b border-gray-100 dark:border-white/5">' +
                '<td class="px-4 py-3">' + escapeHtmlLocal(String(school.school_display).toUpperCase()) + '</td>' +
                '<td class="px-4 py-3">' + school.on_record + '</td>' +
                '<td class="px-4 py-3">' + school.applied + '</td>' +
                '<td class="px-4 py-3">' + school.filing_rate + '%</td>' +
                '</tr>';
        }).join('');
    }

    if (filingYearSelect) {
        filingYearSelect.addEventListener('change', function () {
            var year = filingYearSelect.value;
            filingExportLink.href = config.apiBase + '/api/export_application_filing_report.php?year=' + encodeURIComponent(year);

            fetch(config.apiBase + '/api/get_application_filing_report.php?year=' + encodeURIComponent(year))
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data.status === 'success') {
                        renderFilingReport(data);
                    } else {
                        showToast('error', data.message || 'THE REPORT COULD NOT BE LOADED.');
                    }
                })
                .catch(function () {
                    showToast('error', 'THE REPORT COULD NOT BE LOADED. PLEASE TRY AGAIN.');
                });
        });
    }

    var rankingYearSelect = document.getElementById('ranking-report-year');
    var rankingExportLink = document.getElementById('ranking-report-export');
    var rankingTbody = document.getElementById('ranking-report-tbody');

    function renderRankingReport(data) {
        if (data.rows.length === 0) {
            rankingTbody.innerHTML = '<tr><td colspan="3" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">NO HONOR GRADUATE RECORDS FOUND FOR THIS YEAR.</td></tr>';
            return;
        }

        rankingTbody.innerHTML = data.rows.map(function (row, index) {
            return '<tr class="border-b border-gray-100 dark:border-white/5">' +
                '<td class="px-4 py-3">' + (index + 1) + '</td>' +
                '<td class="px-4 py-3">' + escapeHtmlLocal(String(row.school_display).toUpperCase()) + '</td>' +
                '<td class="px-4 py-3">' + row.honor_count + '</td>' +
                '</tr>';
        }).join('');
    }

    if (rankingYearSelect) {
        rankingYearSelect.addEventListener('change', function () {
            var year = rankingYearSelect.value;
            rankingExportLink.href = config.apiBase + '/api/export_school_ranking_report.php?year=' + encodeURIComponent(year);

            fetch(config.apiBase + '/api/get_school_ranking_report.php?year=' + encodeURIComponent(year))
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data.status === 'success') {
                        renderRankingReport(data);
                    } else {
                        showToast('error', data.message || 'THE REPORT COULD NOT BE LOADED.');
                    }
                })
                .catch(function () {
                    showToast('error', 'THE REPORT COULD NOT BE LOADED. PLEASE TRY AGAIN.');
                });
        });
    }
});
