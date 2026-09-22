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

    function currentPageFromUrl() {
        return parseInt(new URLSearchParams(window.location.search).get('page'), 10) || 1;
    }

    function todayIsoDate() {
        var now = new Date();
        var month = String(now.getMonth() + 1).padStart(2, '0');
        var day = String(now.getDate()).padStart(2, '0');
        return now.getFullYear() + '-' + month + '-' + day;
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

    function renderResults(data) {
        if (!data.has_filters) {
            resultsContainer.innerHTML =
                '<div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-surface-900 p-10 text-center text-gray-500 dark:text-gray-400">' +
                '<p class="text-sm">SEARCH BY NAME, SCHOOL, OR YEAR TO FIND HONOR GRADUATE RECORDS.</p></div>';
            return;
        }

        if (data.rows.length === 0) {
            resultsContainer.innerHTML =
                '<div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-surface-900 p-10 text-center text-gray-500 dark:text-gray-400">' +
                '<p class="text-sm font-medium text-gray-700 dark:text-gray-300">NO HONOR GRADUATE RECORD FOUND.</p></div>';
            return;
        }

        var rowsHtml = data.rows.map(function (row) {
            var displayDate = formatDisplayDate(row.graduation_date);
            var displayUploadedAt = formatDisplayDate(row.uploaded_at);
            var displayApplicationDate = formatDisplayDate(row.application_date);
            var courseDisplay = row.course ? escapeHtml(String(row.course).toUpperCase()) : '—';
            var editBtnHtml = config.isAdmin
                ? '<button type="button" class="edit-graduate-btn text-yellow-600 dark:text-yellow-400 hover:underline ml-3" ' +
                  'data-id="' + escapeHtml(String(row.id)) + '" ' +
                  'data-name="' + escapeHtml(row.graduate_name) + '" ' +
                  'data-course="' + escapeHtml(row.course || '') + '" ' +
                  'data-honor="' + escapeHtml(row.latin_honor) + '" ' +
                  'data-date="' + escapeHtml(row.graduation_date || '') + '" ' +
                  'data-year="' + escapeHtml(String(row.graduation_year)) + '">EDIT</button>'
                : '';
            var applyBtnHtml = parseInt(row.has_applied, 10) === 1
                ? '<span class="inline-block rounded px-1.5 py-0.5 text-[10px] font-medium bg-blue-500/20 text-blue-600 dark:text-blue-400 ml-3">APPLIED</span>'
                : '<button type="button" class="apply-graduate-btn text-blue-600 dark:text-blue-400 hover:underline ml-3" ' +
                  'data-id="' + escapeHtml(String(row.id)) + '" ' +
                  'data-name="' + escapeHtml(row.graduate_name) + '">APPLIED</button>';
            return '<tr class="border-b border-gray-100 dark:border-white/5 hover:bg-gray-100 dark:hover:bg-white/5">' +
                '<td class="px-4 py-3">' + escapeHtml(String(row.graduate_name).toUpperCase()) + '</td>' +
                '<td class="px-4 py-3">' + courseDisplay + '</td>' +
                '<td class="px-4 py-3">' + escapeHtml(String(row.school_name).toUpperCase()) + '</td>' +
                '<td class="px-4 py-3">' + escapeHtml(row.latin_honor) + '</td>' +
                '<td class="px-4 py-3">' + escapeHtml(String(row.graduation_year)) + '</td>' +
                '<td class="px-4 py-3 whitespace-nowrap">' +
                '<button type="button" class="view-graduate-btn text-green-600 dark:text-green-400 hover:underline" ' +
                'data-name="' + escapeHtml(row.graduate_name) + '" ' +
                'data-course="' + escapeHtml(row.course || '') + '" ' +
                'data-school="' + escapeHtml(row.school_name) + '" ' +
                'data-honor="' + escapeHtml(row.latin_honor) + '" ' +
                'data-date="' + escapeHtml(displayDate) + '" ' +
                'data-year="' + escapeHtml(String(row.graduation_year)) + '" ' +
                'data-upload-id="' + escapeHtml(String(row.upload_id)) + '" ' +
                'data-filename="' + escapeHtml(row.original_filename) + '" ' +
                'data-uploaded-at="' + escapeHtml(displayUploadedAt) + '">VIEW</button>' +
                applyBtnHtml +
                editBtnHtml +
                '</td>' +
                '<td class="px-4 py-3">' + escapeHtml(displayApplicationDate) + '</td>' +
                '</tr>';
        }).join('');

        var pagination = data.pagination;
        var paginationHtml =
            '<div id="lookup-pagination" class="flex items-center justify-between mt-4 text-sm text-gray-500 dark:text-gray-400">' +
            '<span>PAGE ' + pagination.page + ' OF ' + pagination.total_pages + ' (' + pagination.total_rows + ' RECORDS)</span>' +
            '<div class="flex gap-2">' +
            (pagination.page > 1 ? '<button type="button" class="lookup-page-btn rounded-lg border border-gray-200 dark:border-white/10 px-3 py-1.5 hover:bg-gray-100 dark:hover:bg-white/5" data-page="' + (pagination.page - 1) + '">PREV</button>' : '') +
            (pagination.page < pagination.total_pages ? '<button type="button" class="lookup-page-btn rounded-lg border border-gray-200 dark:border-white/10 px-3 py-1.5 hover:bg-gray-100 dark:hover:bg-white/5" data-page="' + (pagination.page + 1) + '">NEXT</button>' : '') +
            '</div></div>';

        resultsContainer.innerHTML =
            '<div class="bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl overflow-x-auto"><table class="w-full text-sm text-left">' +
            '<thead><tr class="text-sm font-semibold text-gray-900 dark:text-white border-b border-gray-200 dark:border-white/10">' +
            '<th class="px-4 py-3">GRADUATE NAME</th><th class="px-4 py-3">DEGREE/COURSE</th><th class="px-4 py-3">SCHOOL</th><th class="px-4 py-3">LATIN HONOR</th>' +
            '<th class="px-4 py-3">GRADUATION YEAR</th><th class="px-4 py-3">ACTION</th><th class="px-4 py-3">APPLIED</th></tr></thead>' +
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
            '<div><span class="text-xs text-accent-600 dark:text-accent-400">GRADUATE NAME</span><p class="pl-3 text-accent-800 dark:text-accent-300">' + escapeHtml(data.name) + '</p></div>' +
            '<div><span class="text-xs text-accent-600 dark:text-accent-400">DEGREE/COURSE</span><p class="pl-3 text-accent-800 dark:text-accent-300">' + escapeHtml(data.course || '—') + '</p></div>' +
            '<div><span class="text-xs text-accent-600 dark:text-accent-400">SCHOOL</span><p class="pl-3 text-accent-800 dark:text-accent-300">' + escapeHtml(data.school) + '</p></div>' +
            '<div><span class="text-xs text-accent-600 dark:text-accent-400">LATIN HONOR</span><p class="pl-3 text-accent-800 dark:text-accent-300">' + escapeHtml(data.honor) + '</p></div>' +
            '<div><span class="text-xs text-accent-600 dark:text-accent-400">DATE OF GRADUATION</span><p class="pl-3 text-accent-800 dark:text-accent-300">' + escapeHtml(data.date) + '</p></div>' +
            '<div><span class="text-xs text-accent-600 dark:text-accent-400">SOURCE PDF</span><p class="pl-3 text-accent-800 dark:text-accent-300">' + escapeHtml(data.filename) + ' (UPLOADED ' + escapeHtml(data.uploadedAt) + ')</p></div>' +
            '</div>';

        showModal({
            title: 'HONOR GRADUATE RECORD',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CLOSE', className: 'rounded-lg px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5' },
                {
                    label: 'VIEW SOURCE PDF',
                    className: 'rounded-lg px-4 py-2 text-sm bg-accent-600 hover:bg-accent-500 text-white',
                    onClick: function () {
                        openPdfDrawer(config.apiBase + '/api/serve_pdf.php?id=' + encodeURIComponent(data.uploadId), data.filename);
                    },
                },
            ],
        });
    }

    function openEditModal(defaults) {
        var honors = ['CUM LAUDE', 'MAGNA CUM LAUDE', 'SUMMA CUM LAUDE'];
        var optionsHtml = honors.map(function (opt) {
            var selected = opt === defaults.honor ? ' selected' : '';
            return '<option value="' + opt + '"' + selected + '>' + opt + '</option>';
        }).join('');

        var bodyHtml =
            '<div class="space-y-3">' +
            '<div><label class="block text-xs text-accent-600 dark:text-accent-400 mb-1">GRADUATE NAME</label>' +
            '<input id="modal-graduate-name" type="text" value="' + escapeHtml(defaults.name || '') + '" class="w-full rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm"></div>' +
            '<div><label class="block text-xs text-accent-600 dark:text-accent-400 mb-1">DEGREE/COURSE</label>' +
            '<input id="modal-course" type="text" value="' + escapeHtml(defaults.course || '') + '" class="w-full rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm"></div>' +
            '<div><label class="block text-xs text-accent-600 dark:text-accent-400 mb-1">LATIN HONOR</label>' +
            '<select id="modal-latin-honor" class="w-full rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm">' + optionsHtml + '</select></div>' +
            '<div><label class="block text-xs text-accent-600 dark:text-accent-400 mb-1">DATE OF GRADUATION</label>' +
            '<input id="modal-graduation-date" type="date" value="' + escapeHtml(defaults.date || '') + '" class="w-full rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm"></div>' +
            '<div><label class="block text-xs text-accent-600 dark:text-accent-400 mb-1">YEAR GRADUATED</label>' +
            '<input id="modal-graduation-year" type="text" value="' + escapeHtml(defaults.year || '') + '" class="w-full rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm"></div>' +
            '</div>';

        showModal({
            title: 'EDIT HONOR GRADUATE RECORD',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: 'rounded-lg px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5' },
                {
                    label: 'SAVE',
                    className: 'rounded-lg px-4 py-2 text-sm bg-accent-600 hover:bg-accent-500 text-white',
                    onClick: function (modalClose) {
                        postJson(config.apiBase + '/api/update_saved_graduate.php', {
                            csrf_token: config.csrfToken,
                            id: defaults.id,
                            graduate_name: document.getElementById('modal-graduate-name').value,
                            course: document.getElementById('modal-course').value,
                            latin_honor: document.getElementById('modal-latin-honor').value,
                            graduation_date: document.getElementById('modal-graduation-date').value,
                            graduation_year: document.getElementById('modal-graduation-year').value,
                        })
                            .then(function (data) {
                                if (data.status === 'success') {
                                    showToast('success', data.message);
                                    modalClose();
                                    performSearch(currentParams(currentPageFromUrl()), false);
                                } else {
                                    showToast('error', data.message);
                                }
                            })
                            .catch(function () {
                                showToast('error', 'THE RECORD COULD NOT BE SAVED. PLEASE TRY AGAIN.');
                            });
                    },
                },
            ],
        });
    }

    function openApplyModal(defaults) {
        var bodyHtml =
            '<div class="space-y-3">' +
            '<div><span class="text-xs text-accent-600 dark:text-accent-400">NAME</span><p class="pl-3 text-accent-800 dark:text-accent-300">' + escapeHtml(defaults.name) + '</p></div>' +
            '<div><label class="block text-xs text-accent-600 dark:text-accent-400 mb-1" for="modal-application-date">DATE OF APPLICATION</label>' +
            '<input id="modal-application-date" type="date" value="' + escapeHtml(todayIsoDate()) + '" class="w-full rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm"></div>' +
            '</div>';

        showModal({
            title: 'HONOR GRADUATE ELIGIBLE',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: 'rounded-lg px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5' },
                {
                    label: 'CONFIRM',
                    className: 'rounded-lg px-4 py-2 text-sm bg-accent-600 hover:bg-accent-500 text-white',
                    onClick: function (modalClose) {
                        var applicationDate = document.getElementById('modal-application-date').value;
                        if (!applicationDate) {
                            showToast('error', 'PLEASE SELECT A DATE OF APPLICATION.');
                            return;
                        }
                        postJson(config.apiBase + '/api/file_application.php', {
                            csrf_token: config.csrfToken,
                            graduate_id: defaults.id,
                            application_date: applicationDate,
                        })
                            .then(function (data) {
                                if (data.status === 'success') {
                                    showToast('success', data.message);
                                    modalClose();
                                    performSearch(currentParams(currentPageFromUrl()), false);
                                } else {
                                    showToast('error', data.message);
                                }
                            })
                            .catch(function () {
                                showToast('error', 'THE APPLICATION COULD NOT BE RECORDED. PLEASE TRY AGAIN.');
                            });
                    },
                },
            ],
        });
    }

    function buildSchoolOptionsHtml(selectedId) {
        var sourceSelect = document.getElementById('filter-school');
        var options = Array.prototype.slice.call(sourceSelect.options).filter(function (opt) {
            return opt.value !== '';
        });
        var hasSelection = selectedId !== undefined && selectedId !== null && String(selectedId) !== '';

        return '<option value="" disabled' + (hasSelection ? '' : ' selected') + '>SELECT A SCHOOL</option>' +
            options.map(function (opt) {
                var selected = hasSelection && String(opt.value) === String(selectedId) ? ' selected' : '';
                return '<option value="' + opt.value + '"' + selected + '>' + escapeHtml(opt.textContent) + '</option>';
            }).join('');
    }

    function loadYearsForSchool(schoolId, yearSelect, preselectYear) {
        yearSelect.disabled = true;
        yearSelect.innerHTML = '<option value="" disabled selected>LOADING YEARS...</option>';

        fetch(config.apiBase + '/api/get_school_graduation_years.php?school_id=' + encodeURIComponent(schoolId))
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (data.status === 'success' && data.years.length > 0) {
                    var hasPreselect = preselectYear !== undefined && preselectYear !== null && String(preselectYear) !== '';
                    yearSelect.innerHTML = '<option value="" disabled' + (hasPreselect ? '' : ' selected') + '>SELECT A YEAR</option>' +
                        data.years.map(function (y) {
                            var selected = hasPreselect && String(y) === String(preselectYear) ? ' selected' : '';
                            return '<option value="' + y + '"' + selected + '>' + y + '</option>';
                        }).join('');
                    yearSelect.disabled = false;
                } else {
                    yearSelect.innerHTML = '<option value="" disabled selected>NO RECORDS FOR THIS SCHOOL</option>';
                    yearSelect.disabled = true;
                }
            })
            .catch(function () {
                yearSelect.innerHTML = '<option value="" disabled selected>COULD NOT LOAD YEARS</option>';
                yearSelect.disabled = true;
            });
    }

    function openConfirmBulkUpdateModal(values) {
        var submitting = false;
        var bodyHtml =
            '<div class="space-y-2">' +
            '<div class="flex justify-between gap-4 border-b border-gray-100 dark:border-white/5 pb-1">' +
            '<span class="text-gray-500 dark:text-gray-400">SCHOOL</span>' +
            '<span class="text-gray-900 dark:text-white text-right">' + escapeHtml(values.schoolName) + '</span></div>' +
            '<div class="flex justify-between gap-4 border-b border-gray-100 dark:border-white/5 pb-1">' +
            '<span class="text-gray-500 dark:text-gray-400">YEAR</span>' +
            '<span class="text-gray-900 dark:text-white text-right">' + escapeHtml(values.year) + '</span></div>' +
            '<div class="flex justify-between gap-4 border-b border-gray-100 dark:border-white/5 pb-1">' +
            '<span class="text-gray-500 dark:text-gray-400">NEW DATE OF GRADUATION</span>' +
            '<span class="text-gray-900 dark:text-white text-right">' + escapeHtml(formatDisplayDate(values.date)) + '</span></div>' +
            '<p class="text-sm text-yellow-600 dark:text-yellow-400 pt-2">THIS WILL OVERWRITE THE DATE OF GRADUATION FOR EVERY HONOR GRADUATE RECORD FROM THIS SCHOOL AND YEAR. THIS CANNOT BE UNDONE.</p>' +
            '</div>';

        showModal({
            title: 'CONFIRM GRADUATION DATE UPDATE',
            bodyHtml: bodyHtml,
            buttons: [
                {
                    label: 'GO BACK',
                    className: 'rounded-lg px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5',
                    onClick: function (close) {
                        close();
                        openBulkUpdateDateModal(values);
                    },
                },
                {
                    label: 'CONFIRM',
                    className: 'rounded-lg px-4 py-2 text-sm bg-accent-600 hover:bg-accent-500 text-white',
                    onClick: function (modalClose) {
                        if (submitting) {
                            return;
                        }
                        submitting = true;
                        postJson(config.apiBase + '/api/bulk_update_graduation_date.php', {
                            csrf_token: config.csrfToken,
                            school_id: values.schoolId,
                            year: values.year,
                            graduation_date: values.date,
                        })
                            .then(function (data) {
                                if (data.status === 'success') {
                                    showToast('success', data.message);
                                    modalClose();
                                    performSearch(currentParams(currentPageFromUrl()), false);
                                } else {
                                    submitting = false;
                                    showToast('error', data.message);
                                }
                            })
                            .catch(function () {
                                submitting = false;
                                showToast('error', 'THE RECORDS COULD NOT BE UPDATED. PLEASE TRY AGAIN.');
                            });
                    },
                },
            ],
        });
    }

    function openBulkUpdateDateModal(prefill) {
        prefill = prefill || {};
        var bodyHtml =
            '<div class="space-y-3">' +
            '<div><label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">SCHOOL NAME</label>' +
            '<select id="modal-bulk-school" class="w-full rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm">' +
            buildSchoolOptionsHtml(prefill.schoolId) +
            '</select></div>' +
            '<div><label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">YEAR</label>' +
            '<select id="modal-bulk-year" class="w-full rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm" disabled>' +
            '<option value="" disabled selected>SELECT A SCHOOL FIRST</option>' +
            '</select></div>' +
            '<div><label class="block text-xs text-gray-500 dark:text-gray-400 mb-1">DATE OF GRADUATION</label>' +
            '<input id="modal-bulk-date" type="date" value="' + escapeHtml(prefill.date || '') + '" class="w-full rounded-lg bg-gray-100 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm"></div>' +
            '<p class="text-xs text-gray-500 dark:text-gray-400">THIS SETS THE DATE OF GRADUATION FOR EVERY HONOR GRADUATE RECORD FROM THE SELECTED SCHOOL AND YEAR.</p>' +
            '</div>';

        showModal({
            title: 'UPDATE GRADUATION DATE',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: 'rounded-lg px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5' },
                {
                    label: 'CONTINUE',
                    className: 'rounded-lg px-4 py-2 text-sm bg-accent-600 hover:bg-accent-500 text-white',
                    onClick: function (modalClose) {
                        var schoolSelect = document.getElementById('modal-bulk-school');
                        var yearSelect = document.getElementById('modal-bulk-year');
                        var dateInput = document.getElementById('modal-bulk-date');

                        var schoolId = schoolSelect.value;
                        var schoolName = schoolSelect.selectedIndex >= 0 ? schoolSelect.options[schoolSelect.selectedIndex].textContent : '';
                        var year = yearSelect.value;
                        var date = dateInput.value;

                        if (!schoolId) {
                            showToast('error', 'PLEASE SELECT A SCHOOL.');
                            return;
                        }
                        if (!year) {
                            showToast('error', 'PLEASE SELECT A YEAR.');
                            return;
                        }
                        if (!date) {
                            showToast('error', 'PLEASE SELECT A DATE OF GRADUATION.');
                            return;
                        }

                        modalClose();
                        openConfirmBulkUpdateModal({ schoolId: schoolId, schoolName: schoolName, year: year, date: date });
                    },
                },
            ],
        });

        var schoolSelect = document.getElementById('modal-bulk-school');
        var yearSelect = document.getElementById('modal-bulk-year');

        schoolSelect.addEventListener('change', function () {
            if (schoolSelect.value) {
                loadYearsForSchool(schoolSelect.value, yearSelect);
            }
        });

        if (prefill.schoolId) {
            loadYearsForSchool(prefill.schoolId, yearSelect, prefill.year);
        }
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        performSearch(currentParams(1), true);
    });

    var bulkUpdateDateBtn = document.getElementById('bulk-update-date-btn');
    if (bulkUpdateDateBtn) {
        bulkUpdateDateBtn.addEventListener('click', function () {
            openBulkUpdateDateModal();
        });
    }

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

        var applyBtn = event.target.closest('.apply-graduate-btn');
        if (applyBtn) {
            openApplyModal({
                id: applyBtn.dataset.id,
                name: applyBtn.dataset.name,
            });
            return;
        }

        var editBtn = event.target.closest('.edit-graduate-btn');
        if (editBtn) {
            openEditModal({
                id: editBtn.dataset.id,
                name: editBtn.dataset.name,
                course: editBtn.dataset.course,
                honor: editBtn.dataset.honor,
                date: editBtn.dataset.date,
                year: editBtn.dataset.year,
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
