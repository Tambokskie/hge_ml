document.addEventListener('DOMContentLoaded', function () {
    var config = window.HGE_SCHOOL_RECORDS;
    var form = document.getElementById('school-records-filter-form');
    var resultsContainer = document.getElementById('school-records-results');
    if (!config || !form || !resultsContainer) {
        return;
    }

    function formatSchoolDisplayName(schoolName, campus) {
        campus = (campus || '').trim();
        return campus === '' ? schoolName : schoolName + '-' + campus;
    }

    function formatUploadedAt(dateStr) {
        var d = new Date(String(dateStr).replace(' ', 'T'));
        if (isNaN(d.getTime())) {
            return '—';
        }
        var months = ['JANUARY', 'FEBRUARY', 'MARCH', 'APRIL', 'MAY', 'JUNE', 'JULY', 'AUGUST', 'SEPTEMBER', 'OCTOBER', 'NOVEMBER', 'DECEMBER'];
        return months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
    }

    function currentParams(page) {
        return {
            school_id: document.getElementById('filter-school').value,
            school_year: document.getElementById('filter-year').value,
            page: page || 1,
        };
    }

    function currentPageFromUrl() {
        return parseInt(new URLSearchParams(window.location.search).get('page'), 10) || 1;
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

    function updateFoundPill(totalRows) {
        var pill = document.getElementById('school-records-found');
        if (!pill) {
            return;
        }
        pill.textContent = totalRows + ' UPLOAD' + (totalRows === 1 ? '' : 'S') + ' FOUND';
    }

    function bindDeleteButtons() {
        resultsContainer.querySelectorAll('.delete-upload-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var label = btn.dataset.school + ' (' + btn.dataset.year + ')';
                showModal({
                    icon: modalHeaderIcon('trash'),
                    iconClassName: 'bg-csc-red-600/10 text-csc-red-600 dark:text-csc-red-400',
                    title: 'DELETE UPLOAD',
                    bodyHtml: '<p>PERMANENTLY DELETE <span class="font-medium text-gray-900 dark:text-white">' + escapeHtml(label) + '</span> AND ITS SOURCE PDF? THIS CANNOT BE UNDONE.</p>',
                    buttons: [
                        { label: 'CANCEL', className: MODAL_BTN.secondary },
                        {
                            label: 'DELETE',
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
                                            performSearch(currentParams(currentPageFromUrl()), false);
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
    }

    function renderResults(data) {
        if (data.rows.length === 0) {
            updateFoundPill(0);
            resultsContainer.innerHTML =
                '<div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-surface-900 p-10 text-center text-gray-500 dark:text-gray-400">' +
                '<p class="text-sm font-medium text-gray-700 dark:text-gray-300">NO UPLOADS FOUND.</p></div>';
            return;
        }

        updateFoundPill(data.pagination.total_rows);

        var rowsHtml = data.rows.map(function (upload) {
            var editBtnHtml = upload.extraction_status === 'reviewed'
                ? '<a href="' + config.reviewUrl + '?upload_id=' + encodeURIComponent(upload.id) + '" class="rounded-lg bg-green-500/10 hover:bg-green-500/20 text-green-600 dark:text-green-400 p-1.5" title="Edit saved records">' + actionIcon('pen') + '</a>'
                : '';
            var deleteBtnHtml = parseInt(upload.records_extracted, 10) === 0
                ? '<button type="button" class="delete-upload-btn rounded-lg bg-csc-red-600/10 hover:bg-csc-red-600/20 text-csc-red-600 dark:text-csc-red-400 p-1.5" title="Delete upload" ' +
                  'data-upload-id="' + escapeHtml(String(upload.id)) + '" ' +
                  'data-school="' + escapeHtml(formatSchoolDisplayName(upload.school_name, upload.campus)) + '" ' +
                  'data-year="' + escapeHtml(String(upload.school_year)) + '">' + actionIcon('trash') + '</button>'
                : '';

            return '<tr class="border-b border-gray-100 dark:border-white/5 hover:bg-gray-100 dark:hover:bg-white/5">' +
                '<td class="px-4 py-3">' + escapeHtml(formatSchoolDisplayName(upload.school_name, upload.campus).toUpperCase()) + '</td>' +
                '<td class="px-4 py-3">' + escapeHtml(String(upload.school_year)) + '</td>' +
                '<td class="px-4 py-3">' + escapeHtml(String(upload.records_extracted)) + ' RECORDS</td>' +
                '<td class="px-4 py-3">' + escapeHtml(formatUploadedAt(upload.uploaded_at)) + '</td>' +
                '<td class="px-4 py-3">' + escapeHtml(upload.original_filename) + '</td>' +
                '<td class="px-4 py-3 text-right"><div class="flex items-center justify-end gap-1.5">' +
                '<a href="' + config.viewUploadUrl + '?id=' + encodeURIComponent(upload.id) + '" class="rounded-lg bg-csc-navy-600/10 hover:bg-csc-navy-600/20 text-csc-navy-600 dark:text-csc-navy-300 p-1.5" title="View upload">' + actionIcon('eye') + '</a>' +
                editBtnHtml + deleteBtnHtml +
                '</div></td></tr>';
        }).join('');

        var pagination = data.pagination;
        var rangeStart = pagination.total_rows === 0 ? 0 : pagination.offset + 1;
        var rangeEnd = Math.min(pagination.offset + pagination.per_page, pagination.total_rows);
        var pageButtonsHtml =
            (pagination.page > 1
                ? '<button type="button" class="school-records-page-btn rounded-lg border border-gray-200 dark:border-white/10 p-1.5 hover:bg-gray-100 dark:hover:bg-white/5" data-page="' + (pagination.page - 1) + '" aria-label="Previous page">' + actionIcon('chevronLeft') + '</button>'
                : '') +
            paginationWindow(pagination.page, pagination.total_pages).map(function (pageNum) {
                var isCurrent = pageNum === pagination.page;
                return '<button type="button" class="school-records-page-btn min-w-[2.25rem] rounded-lg px-2.5 py-1.5 text-sm font-medium ' +
                    (isCurrent ? 'bg-csc-navy-600 text-white' : 'border border-gray-200 dark:border-white/10 hover:bg-gray-100 dark:hover:bg-white/5') +
                    '" data-page="' + pageNum + '">' + pageNum + '</button>';
            }).join('') +
            (pagination.page < pagination.total_pages
                ? '<button type="button" class="school-records-page-btn rounded-lg border border-gray-200 dark:border-white/10 p-1.5 hover:bg-gray-100 dark:hover:bg-white/5" data-page="' + (pagination.page + 1) + '" aria-label="Next page">' + actionIcon('chevronRight') + '</button>'
                : '');
        var paginationHtml =
            '<div id="school-records-pagination" class="flex flex-wrap items-center justify-between gap-3 mt-4 text-sm text-gray-500 dark:text-gray-400">' +
            '<span>SHOWING ' + rangeStart + ' TO ' + rangeEnd + ' OF ' + pagination.total_rows + ' ENTRIES</span>' +
            '<div class="flex items-center gap-1.5">' + pageButtonsHtml + '</div></div>';

        resultsContainer.innerHTML =
            '<div class="bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl overflow-x-auto"><table class="w-full text-sm text-left">' +
            '<thead><tr class="text-sm font-semibold text-gray-900 dark:text-white border-b border-gray-200 dark:border-white/10">' +
            '<th class="px-4 py-3">SCHOOL</th><th class="px-4 py-3">YEAR</th><th class="px-4 py-3">RECORDS EXTRACTED</th>' +
            '<th class="px-4 py-3">DATE UPLOADED</th><th class="px-4 py-3">FILE NAME</th><th class="px-4 py-3 text-right">ACTION</th></tr></thead>' +
            '<tbody id="school-records-tbody">' + rowsHtml + '</tbody></table></div>' + paginationHtml;

        bindDeleteButtons();
    }

    function performSearch(params, updateHistory) {
        var query = buildQueryString(params);

        if (updateHistory) {
            var newUrl = window.location.pathname + (query ? '?' + query : '');
            window.history.pushState(params, '', newUrl);
        }

        fetch(config.apiBase + '/api/search_school_records.php' + (query ? '?' + query : ''))
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

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        performSearch(currentParams(1), true);
    });

    var clearFiltersBtn = document.getElementById('school-records-clear-filters-btn');
    if (clearFiltersBtn) {
        clearFiltersBtn.addEventListener('click', function () {
            document.getElementById('filter-school').value = '';
            document.getElementById('filter-year').value = '';
            performSearch(currentParams(1), true);
        });
    }

    resultsContainer.addEventListener('click', function (event) {
        var pageBtn = event.target.closest('.school-records-page-btn');
        if (pageBtn) {
            performSearch(currentParams(parseInt(pageBtn.dataset.page, 10)), true);
        }
    });

    bindDeleteButtons();

    window.addEventListener('popstate', function () {
        var urlParams = new URLSearchParams(window.location.search);
        var params = {
            school_id: urlParams.get('school_id') || '',
            school_year: urlParams.get('school_year') || '',
            page: urlParams.get('page') || 1,
        };
        document.getElementById('filter-school').value = params.school_id;
        document.getElementById('filter-year').value = params.school_year;
        performSearch(params, false);
    });
});
