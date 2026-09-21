document.addEventListener('DOMContentLoaded', function () {
    var config = window.HGE_ACTIVITY_LOG;
    var form = document.getElementById('activity-filter-form');
    var resultsContainer = document.getElementById('activity-results');
    if (!config || !form || !resultsContainer) {
        return;
    }

    var MONTHS = ['JANUARY', 'FEBRUARY', 'MARCH', 'APRIL', 'MAY', 'JUNE', 'JULY', 'AUGUST', 'SEPTEMBER', 'OCTOBER', 'NOVEMBER', 'DECEMBER'];

    function formatTimestamp(value) {
        var parts = String(value).split(/[- :]/);
        if (parts.length < 5) {
            return String(value);
        }
        var year = parseInt(parts[0], 10);
        var month = MONTHS[parseInt(parts[1], 10) - 1];
        var day = parseInt(parts[2], 10);
        var hour = parseInt(parts[3], 10);
        var minute = parts[4];
        var ampm = hour >= 12 ? 'PM' : 'AM';
        var hour12 = hour % 12 === 0 ? 12 : hour % 12;
        return month + ' ' + day + ', ' + year + ' ' + hour12 + ':' + minute + ' ' + ampm;
    }

    function currentParams(page) {
        return {
            user_id: document.getElementById('filter-user').value,
            action: document.getElementById('filter-action').value,
            date_from: document.getElementById('filter-date-from').value,
            date_to: document.getElementById('filter-date-to').value,
            page: page || 1,
        };
    }

    function buildQueryString(params) {
        var pairs = [];
        Object.keys(params).forEach(function (key) {
            var value = params[key];
            if (value !== null && value !== undefined && value !== '') {
                pairs.push(encodeURIComponent(key) + '=' + encodeURIComponent(value));
            }
        });
        return pairs.join('&');
    }

    function renderResults(data) {
        if (data.rows.length === 0) {
            resultsContainer.innerHTML =
                '<div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-surface-900 p-10 text-center text-gray-500 dark:text-gray-400">' +
                '<p class="text-sm font-medium text-gray-700 dark:text-gray-300">NO ACTIVITY LOG ENTRIES FOUND.</p></div>';
            return;
        }

        var rowsHtml = data.rows.map(function (row) {
            var userLabel = row.user_name ? row.user_name.toUpperCase() : 'SYSTEM';
            var details = row.details ? row.details : '—';
            return '<tr class="border-b border-gray-100 dark:border-white/5 hover:bg-gray-100 dark:hover:bg-white/5">' +
                '<td class="px-4 py-3">' + escapeHtml(formatTimestamp(row.created_at)) + '</td>' +
                '<td class="px-4 py-3">' + escapeHtml(userLabel) + '</td>' +
                '<td class="px-4 py-3">' + escapeHtml(String(row.action).toUpperCase()) + '</td>' +
                '<td class="px-4 py-3">' + escapeHtml(details) + '</td>' +
                '</tr>';
        }).join('');

        var pagination = data.pagination;
        var paginationHtml =
            '<div id="activity-pagination" class="flex items-center justify-between mt-4 text-sm text-gray-500 dark:text-gray-400">' +
            '<span>PAGE ' + pagination.page + ' OF ' + pagination.total_pages + ' (' + pagination.total_rows + ' ENTRIES)</span>' +
            '<div class="flex gap-2">' +
            (pagination.page > 1 ? '<button type="button" class="activity-page-btn rounded-lg border border-gray-200 dark:border-white/10 px-3 py-1.5 hover:bg-gray-100 dark:hover:bg-white/5" data-page="' + (pagination.page - 1) + '">PREV</button>' : '') +
            (pagination.page < pagination.total_pages ? '<button type="button" class="activity-page-btn rounded-lg border border-gray-200 dark:border-white/10 px-3 py-1.5 hover:bg-gray-100 dark:hover:bg-white/5" data-page="' + (pagination.page + 1) + '">NEXT</button>' : '') +
            '</div></div>';

        resultsContainer.innerHTML =
            '<div class="bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl overflow-x-auto"><table class="w-full text-sm text-left">' +
            '<thead><tr class="text-xs text-gray-600 dark:text-gray-500 border-b border-gray-200 dark:border-white/10">' +
            '<th class="px-4 py-3">TIMESTAMP</th><th class="px-4 py-3">USER</th><th class="px-4 py-3">ACTION</th><th class="px-4 py-3">DETAILS</th></tr></thead>' +
            '<tbody id="activity-results-tbody">' + rowsHtml + '</tbody></table></div>' + paginationHtml;
    }

    function performSearch(params, updateHistory) {
        var query = buildQueryString(params);

        if (updateHistory) {
            var newUrl = window.location.pathname + (query ? '?' + query : '');
            window.history.pushState(params, '', newUrl);
        }

        fetch(config.apiBase + '/api/search_activity_log.php' + (query ? '?' + query : ''))
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (data.status === 'success') {
                    renderResults(data);
                } else {
                    showToast('error', data.message);
                }
            })
            .catch(function () {
                showToast('error', 'THE ACTIVITY LOG COULD NOT BE LOADED. PLEASE TRY AGAIN.');
            });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        performSearch(currentParams(1), true);
    });

    resultsContainer.addEventListener('click', function (event) {
        var pageBtn = event.target.closest('.activity-page-btn');
        if (pageBtn) {
            performSearch(currentParams(parseInt(pageBtn.dataset.page, 10)), true);
        }
    });

    window.addEventListener('popstate', function () {
        var urlParams = new URLSearchParams(window.location.search);
        var params = {
            user_id: urlParams.get('user_id') || '',
            action: urlParams.get('action') || '',
            date_from: urlParams.get('date_from') || '',
            date_to: urlParams.get('date_to') || '',
            page: urlParams.get('page') || 1,
        };
        document.getElementById('filter-user').value = params.user_id;
        document.getElementById('filter-action').value = params.action;
        document.getElementById('filter-date-from').value = params.date_from;
        document.getElementById('filter-date-to').value = params.date_to;
        performSearch(params, false);
    });
});
