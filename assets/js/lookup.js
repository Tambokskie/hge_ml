document.addEventListener('DOMContentLoaded', function () {
    var config = window.HGE_LOOKUP;
    var form = document.getElementById('lookup-filter-form');
    var resultsContainer = document.getElementById('lookup-results');
    if (!config || !form || !resultsContainer) {
        return;
    }

    var MONTHS = ['JANUARY', 'FEBRUARY', 'MARCH', 'APRIL', 'MAY', 'JUNE', 'JULY', 'AUGUST', 'SEPTEMBER', 'OCTOBER', 'NOVEMBER', 'DECEMBER'];

    function formatDisplayDate(dateStr) {
        if (!dateStr) {
            return '—';
        }
        var parts = String(dateStr).split(' ')[0].split('-');
        if (parts.length !== 3) {
            return '—';
        }
        var year = parseInt(parts[0], 10);
        var month = MONTHS[parseInt(parts[1], 10) - 1];
        var day = parseInt(parts[2], 10);
        if (!month || !year || !day) {
            return '—';
        }
        return month + ' ' + day + ', ' + year;
    }

    function currentParams(page) {
        return {
            school_id: document.getElementById('filter-school').value,
            year: document.getElementById('filter-year').value,
            name: document.getElementById('filter-name').value,
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
        if (!data.has_filters) {
            resultsContainer.innerHTML =
                '<div class="rounded-xl border border-white/10 bg-surface-900 p-10 text-center text-gray-400">' +
                '<p class="text-sm">SEARCH BY NAME, SCHOOL, OR YEAR TO FIND HONOR GRADUATE RECORDS.</p></div>';
            return;
        }

        if (data.rows.length === 0) {
            resultsContainer.innerHTML =
                '<div class="rounded-xl border border-white/10 bg-surface-900 p-10 text-center text-gray-400">' +
                '<p class="text-sm font-medium text-gray-300">NO HONOR GRADUATE RECORD FOUND.</p></div>';
            return;
        }

        var rowsHtml = data.rows.map(function (row) {
            var displayDate = formatDisplayDate(row.graduation_date);
            var displayUploadedAt = formatDisplayDate(row.uploaded_at);
            var courseDisplay = row.course ? escapeHtml(String(row.course).toUpperCase()) : '—';
            return '<tr class="border-b border-white/5 hover:bg-white/5">' +
                '<td class="px-4 py-3">' + escapeHtml(String(row.graduate_name).toUpperCase()) + '</td>' +
                '<td class="px-4 py-3">' + courseDisplay + '</td>' +
                '<td class="px-4 py-3">' + escapeHtml(String(row.school_name).toUpperCase()) + '</td>' +
                '<td class="px-4 py-3">' + escapeHtml(row.latin_honor) + '</td>' +
                '<td class="px-4 py-3">' + escapeHtml(String(row.graduation_year)) + '</td>' +
                '<td class="px-4 py-3">' +
                '<button type="button" class="view-graduate-btn text-accent-400 hover:underline" ' +
                'data-name="' + escapeHtml(row.graduate_name) + '" ' +
                'data-course="' + escapeHtml(row.course || '') + '" ' +
                'data-school="' + escapeHtml(row.school_name) + '" ' +
                'data-honor="' + escapeHtml(row.latin_honor) + '" ' +
                'data-date="' + escapeHtml(displayDate) + '" ' +
                'data-year="' + escapeHtml(String(row.graduation_year)) + '" ' +
                'data-upload-id="' + escapeHtml(String(row.upload_id)) + '" ' +
                'data-filename="' + escapeHtml(row.original_filename) + '" ' +
                'data-uploaded-at="' + escapeHtml(displayUploadedAt) + '">VIEW</button>' +
                '</td></tr>';
        }).join('');

        var pagination = data.pagination;
        var paginationHtml =
            '<div id="lookup-pagination" class="flex items-center justify-between mt-4 text-sm text-gray-400">' +
            '<span>PAGE ' + pagination.page + ' OF ' + pagination.total_pages + ' (' + pagination.total_rows + ' RECORDS)</span>' +
            '<div class="flex gap-2">' +
            (pagination.page > 1 ? '<button type="button" class="lookup-page-btn rounded-lg border border-white/10 px-3 py-1.5 hover:bg-white/5" data-page="' + (pagination.page - 1) + '">PREV</button>' : '') +
            (pagination.page < pagination.total_pages ? '<button type="button" class="lookup-page-btn rounded-lg border border-white/10 px-3 py-1.5 hover:bg-white/5" data-page="' + (pagination.page + 1) + '">NEXT</button>' : '') +
            '</div></div>';

        resultsContainer.innerHTML =
            '<div class="bg-surface-900 border border-white/10 rounded-xl overflow-x-auto"><table class="w-full text-sm text-left">' +
            '<thead><tr class="text-xs text-gray-500 border-b border-white/10">' +
            '<th class="px-4 py-3">GRADUATE NAME</th><th class="px-4 py-3">DEGREE/COURSE</th><th class="px-4 py-3">SCHOOL</th><th class="px-4 py-3">LATIN HONOR</th>' +
            '<th class="px-4 py-3">GRADUATION YEAR</th><th class="px-4 py-3">ACTION</th></tr></thead>' +
            '<tbody id="lookup-results-tbody">' + rowsHtml + '</tbody></table></div>' + paginationHtml;
    }

    function performSearch(params, updateHistory) {
        var query = buildQueryString(params);

        if (updateHistory) {
            var newUrl = window.location.pathname + (query ? '?' + query : '');
            window.history.pushState(params, '', newUrl);
        }

        fetch(config.apiBase + '/api/search_graduates.php' + (query ? '?' + query : ''))
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
                showToast('error', 'THE SEARCH COULD NOT BE COMPLETED. PLEASE TRY AGAIN.');
            });
    }

    function openDetailsModal(data) {
        var bodyHtml =
            '<div class="space-y-2">' +
            '<div><span class="text-xs text-gray-500">GRADUATE NAME</span><p class="text-white">' + escapeHtml(data.name) + '</p></div>' +
            '<div><span class="text-xs text-gray-500">DEGREE/COURSE</span><p class="text-white">' + escapeHtml(data.course || '—') + '</p></div>' +
            '<div><span class="text-xs text-gray-500">SCHOOL</span><p class="text-white">' + escapeHtml(data.school) + '</p></div>' +
            '<div><span class="text-xs text-gray-500">LATIN HONOR</span><p class="text-white">' + escapeHtml(data.honor) + '</p></div>' +
            '<div><span class="text-xs text-gray-500">DATE OF GRADUATION</span><p class="text-white">' + escapeHtml(data.date) + '</p></div>' +
            '<div><span class="text-xs text-gray-500">SOURCE PDF</span><p class="text-white">' + escapeHtml(data.filename) + ' (UPLOADED ' + escapeHtml(data.uploadedAt) + ')</p></div>' +
            '</div>';

        showModal({
            title: 'HONOR GRADUATE RECORD',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CLOSE', className: 'rounded-lg px-4 py-2 text-sm text-gray-300 hover:bg-white/5' },
                {
                    label: 'VIEW SOURCE PDF',
                    className: 'rounded-lg px-4 py-2 text-sm bg-accent-600 hover:bg-accent-500 text-white',
                    onClick: function (close) {
                        window.open(config.apiBase + '/api/serve_pdf.php?id=' + encodeURIComponent(data.uploadId), '_blank');
                        close();
                    },
                },
            ],
        });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        performSearch(currentParams(1), true);
    });

    resultsContainer.addEventListener('click', function (event) {
        var viewBtn = event.target.closest('.view-graduate-btn');
        if (viewBtn) {
            openDetailsModal({
                name: viewBtn.dataset.name,
                course: viewBtn.dataset.course,
                school: viewBtn.dataset.school,
                honor: viewBtn.dataset.honor,
                date: viewBtn.dataset.date,
                filename: viewBtn.dataset.filename,
                uploadedAt: viewBtn.dataset.uploadedAt,
                uploadId: viewBtn.dataset.uploadId,
            });
            return;
        }

        var pageBtn = event.target.closest('.lookup-page-btn');
        if (pageBtn) {
            performSearch(currentParams(parseInt(pageBtn.dataset.page, 10)), true);
        }
    });

    window.addEventListener('popstate', function () {
        var urlParams = new URLSearchParams(window.location.search);
        var params = {
            school_id: urlParams.get('school_id') || '',
            year: urlParams.get('year') || '',
            name: urlParams.get('name') || '',
            page: urlParams.get('page') || 1,
        };
        document.getElementById('filter-school').value = params.school_id;
        document.getElementById('filter-year').value = params.year;
        document.getElementById('filter-name').value = params.name;
        performSearch(params, false);
    });
});
