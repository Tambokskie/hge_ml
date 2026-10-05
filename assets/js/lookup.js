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

    function formatSchoolDisplayName(schoolName, campus) {
        campus = (campus || '').trim();
        return campus === '' ? schoolName : schoolName + '-' + campus;
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

    function updateRecordsFoundPill(totalRows) {
        var pill = document.getElementById('lookup-records-found');
        if (!pill) {
            return;
        }
        if (totalRows === null) {
            pill.classList.add('hidden');
            return;
        }
        pill.textContent = totalRows + ' RECORD' + (totalRows === 1 ? '' : 'S') + ' FOUND';
        pill.classList.remove('hidden');
    }

    function renderResults(data) {
        if (!data.has_filters) {
            updateRecordsFoundPill(null);
            resultsContainer.innerHTML =
                '<div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-surface-900 p-10 text-center text-gray-500 dark:text-gray-400">' +
                '<p class="text-sm">SEARCH BY NAME, SCHOOL, OR YEAR TO FIND HONOR GRADUATE RECORDS.</p></div>';
            return;
        }

        if (data.rows.length === 0) {
            updateRecordsFoundPill(0);
            resultsContainer.innerHTML =
                '<div class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-surface-900 p-10 text-center text-gray-500 dark:text-gray-400">' +
                '<p class="text-sm font-medium text-gray-700 dark:text-gray-300">NO HONOR GRADUATE RECORD FOUND.</p></div>';
            return;
        }

        updateRecordsFoundPill(data.pagination.total_rows);

        var rowsHtml = data.rows.map(function (row, rowIndex) {
            var rowDelay = 'style="animation-delay: ' + (rowIndex * 50) + 'ms"';
            var displayDate = formatDisplayDate(row.graduation_date);
            var displayUploadedAt = formatDisplayDate(row.uploaded_at);
            var displayApplicationDate = formatDisplayDate(row.application_date);
            var courseDisplay = row.course ? escapeHtml(String(row.course).toUpperCase()) : '—';
            var sourceLabel = extractionSourceLabel(row.source);
            var sourceBadgeHtml = sourceLabel
                ? '<span class="ml-1 inline-block rounded px-1.5 py-0.5 text-[10px] font-medium bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-400" title="EXTRACTED VIA ' + sourceLabel + '">' + sourceLabel + '</span>'
                : '';

            var actionBtnIndex = 0;
            var actionBtnDelay = function () {
                return 'style="animation-delay: ' + (rowIndex * 50 + (actionBtnIndex++) * 70) + 'ms"';
            };

            var viewBtnHtml =
                '<button type="button" class="view-graduate-btn rounded-lg bg-csc-navy-600/10 hover:bg-csc-navy-600/20 text-csc-navy-600 dark:text-csc-navy-300 p-1.5 motion-safe:animate-drop-in" ' + actionBtnDelay() + ' title="View record" ' +
                'data-name="' + escapeHtml(row.graduate_name) + '" ' +
                'data-course="' + escapeHtml(row.course || '') + '" ' +
                'data-school="' + escapeHtml(formatSchoolDisplayName(row.school_name, row.campus)) + '" ' +
                'data-honor="' + escapeHtml(row.latin_honor) + '" ' +
                'data-date="' + escapeHtml(displayDate) + '" ' +
                'data-year="' + escapeHtml(String(row.graduation_year)) + '" ' +
                'data-upload-id="' + escapeHtml(String(row.upload_id)) + '" ' +
                'data-filename="' + escapeHtml(row.original_filename) + '" ' +
                'data-uploaded-at="' + escapeHtml(displayUploadedAt) + '">' + actionIcon('eye') + '</button>';

            var applyBtnHtml = parseInt(row.has_applied, 10) === 1
                ? ''
                : '<button type="button" class="apply-graduate-btn rounded-lg bg-green-500/10 hover:bg-green-500/20 text-green-600 dark:text-green-400 p-1.5 motion-safe:animate-drop-in" ' + actionBtnDelay() + ' title="Mark as applied" ' +
                  'data-id="' + escapeHtml(String(row.id)) + '" ' +
                  'data-name="' + escapeHtml(row.graduate_name) + '">' + actionIcon('check') + '</button>';

            var editBtnHtml = config.isAdmin
                ? '<button type="button" class="edit-graduate-btn rounded-lg bg-csc-navy-600/10 hover:bg-csc-navy-600/20 text-csc-navy-600 dark:text-csc-navy-300 p-1.5 motion-safe:animate-drop-in" ' + actionBtnDelay() + ' title="Edit record" ' +
                  'data-id="' + escapeHtml(String(row.id)) + '" ' +
                  'data-name="' + escapeHtml(row.graduate_name) + '" ' +
                  'data-course="' + escapeHtml(row.course || '') + '" ' +
                  'data-honor="' + escapeHtml(row.latin_honor) + '" ' +
                  'data-date="' + escapeHtml(row.graduation_date || '') + '" ' +
                  'data-year="' + escapeHtml(String(row.graduation_year)) + '">' + actionIcon('pen') + '</button>'
                : '';
            var deleteBtnHtml = config.isAdmin
                ? '<button type="button" class="delete-graduate-btn rounded-lg bg-csc-red-600/10 hover:bg-csc-red-600/20 text-csc-red-600 dark:text-csc-red-400 p-1.5 motion-safe:animate-drop-in" ' + actionBtnDelay() + ' title="Delete record" ' +
                  'data-id="' + escapeHtml(String(row.id)) + '" ' +
                  'data-name="' + escapeHtml(row.graduate_name) + '">' + actionIcon('trash') + '</button>'
                : '';

            return '<tr class="border-b border-gray-100 dark:border-white/5 hover:bg-gray-100 dark:hover:bg-white/5 motion-safe:animate-fade-in-up" ' + rowDelay + '>' +
                '<td class="px-4 py-3">' + escapeHtml(String(row.graduate_name).toUpperCase()) + '</td>' +
                '<td class="px-4 py-3">' + courseDisplay + '</td>' +
                '<td class="px-4 py-3">' + escapeHtml(formatSchoolDisplayName(row.school_name, row.campus).toUpperCase()) + '</td>' +
                '<td class="px-4 py-3"><span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ' + honorPillClass(row.latin_honor) + '">' + escapeHtml(row.latin_honor) + '</span>' + sourceBadgeHtml + '</td>' +
                '<td class="px-4 py-3">' + escapeHtml(displayDate) + '</td>' +
                '<td class="px-4 py-3">' + escapeHtml(displayApplicationDate) + '</td>' +
                '<td class="px-4 py-3"><div class="flex items-center justify-end gap-1.5">' +
                viewBtnHtml +
                applyBtnHtml +
                editBtnHtml +
                deleteBtnHtml +
                '</div></td>' +
                '</tr>';
        }).join('');

        var pagination = data.pagination;
        var rangeStart = pagination.total_rows === 0 ? 0 : pagination.offset + 1;
        var rangeEnd = Math.min(pagination.offset + pagination.per_page, pagination.total_rows);
        var pageSelectHtml = '';
        if (pagination.total_pages > 1) {
            var pageOptionsHtml = '';
            for (var p = 1; p <= pagination.total_pages; p++) {
                pageOptionsHtml += '<option value="' + p + '"' + (p === pagination.page ? ' selected' : '') + '>PAGE ' + p + ' OF ' + pagination.total_pages + '</option>';
            }
            pageSelectHtml = '<select class="lookup-page-select rounded-lg border border-gray-200 dark:border-white/10 bg-white dark:bg-surface-800 px-2 py-1.5 text-sm focus:border-csc-navy-500 focus:ring-2 focus:ring-csc-navy-500/20 focus:outline-none" aria-label="Jump to page">' + pageOptionsHtml + '</select>';
        }
        var pageButtonsHtml =
            pageSelectHtml +
            (pagination.page > 1
                ? '<button type="button" class="lookup-page-btn rounded-lg border border-gray-200 dark:border-white/10 p-1.5 hover:bg-gray-100 dark:hover:bg-white/5" data-page="' + (pagination.page - 1) + '" aria-label="Previous page">' + actionIcon('chevronLeft') + '</button>'
                : '') +
            paginationWindow(pagination.page, pagination.total_pages).map(function (pageNum) {
                var isCurrent = pageNum === pagination.page;
                return '<button type="button" class="lookup-page-btn min-w-[2.25rem] rounded-lg px-2.5 py-1.5 text-sm font-medium ' +
                    (isCurrent ? 'bg-csc-navy-600 text-white' : 'border border-gray-200 dark:border-white/10 hover:bg-gray-100 dark:hover:bg-white/5') +
                    '" data-page="' + pageNum + '">' + pageNum + '</button>';
            }).join('') +
            (pagination.page < pagination.total_pages
                ? '<button type="button" class="lookup-page-btn rounded-lg border border-gray-200 dark:border-white/10 p-1.5 hover:bg-gray-100 dark:hover:bg-white/5" data-page="' + (pagination.page + 1) + '" aria-label="Next page">' + actionIcon('chevronRight') + '</button>'
                : '');
        var paginationHtml =
            '<div id="lookup-pagination" class="flex flex-wrap items-center justify-between gap-3 mt-4 text-sm text-gray-500 dark:text-gray-400">' +
            '<span>SHOWING ' + rangeStart + ' TO ' + rangeEnd + ' OF ' + pagination.total_rows + ' ENTRIES</span>' +
            '<div class="flex items-center gap-1.5">' + pageButtonsHtml + '</div></div>';

        resultsContainer.innerHTML =
            '<div class="bg-white dark:bg-surface-900 border border-gray-200 dark:border-white/10 rounded-xl overflow-x-auto overflow-y-hidden"><table class="w-full text-sm text-left">' +
            '<thead><tr class="text-sm font-semibold text-gray-900 dark:text-white border-b border-gray-200 dark:border-white/10">' +
            '<th class="px-4 py-3">GRADUATE NAME</th><th class="px-4 py-3">DEGREE/COURSE</th><th class="px-4 py-3">SCHOOL</th><th class="px-4 py-3">LATIN HONOR</th>' +
            '<th class="px-4 py-3">DATE OF GRADUATION</th><th class="px-4 py-3">APPLIED</th><th class="px-4 py-3 text-right">ACTION</th></tr></thead>' +
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
            '<div><span class="text-xs text-gray-500 dark:text-gray-400">GRADUATE NAME</span><p class="pl-3 text-gray-900 dark:text-white">' + escapeHtml(data.name) + '</p></div>' +
            '<div><span class="text-xs text-gray-500 dark:text-gray-400">DEGREE/COURSE</span><p class="pl-3 text-gray-900 dark:text-white">' + escapeHtml(data.course || '—') + '</p></div>' +
            '<div><span class="text-xs text-gray-500 dark:text-gray-400">SCHOOL</span><p class="pl-3 text-gray-900 dark:text-white">' + escapeHtml(data.school) + '</p></div>' +
            '<div><span class="text-xs text-gray-500 dark:text-gray-400">LATIN HONOR</span><p class="pl-3 text-gray-900 dark:text-white">' + escapeHtml(data.honor) + '</p></div>' +
            '<div><span class="text-xs text-gray-500 dark:text-gray-400">DATE OF GRADUATION</span><p class="pl-3 text-gray-900 dark:text-white">' + escapeHtml(data.date) + '</p></div>' +
            '<div><span class="text-xs text-gray-500 dark:text-gray-400">SOURCE PDF</span><p class="pl-3 text-gray-900 dark:text-white">' + escapeHtml(data.filename) + ' (UPLOADED ' + escapeHtml(data.uploadedAt) + ')</p></div>' +
            '</div>';

        showModal({
            icon: modalHeaderIcon('document'),
            title: 'HONOR GRADUATE RECORD',
            subtitle: 'Saved details for this honor graduate record.',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CLOSE', className: MODAL_BTN.secondary },
                {
                    label: 'VIEW SOURCE PDF',
                    icon: modalButtonIcon('eye'),
                    className: MODAL_BTN.primary,
                    onClick: function () {
                        openPdfDrawer(config.apiBase + '/api/serve_pdf.php?id=' + encodeURIComponent(data.uploadId), data.filename, {
                            school: data.school,
                            uploadedAt: data.uploadedAt,
                        });
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
            '<div class="space-y-4">' +
            modalField({ id: 'modal-graduate-name', label: 'GRADUATE NAME', required: true, icon: 'user', value: defaults.name || '' }) +
            modalField({ id: 'modal-course', label: 'DEGREE/COURSE', icon: 'academic', value: defaults.course || '' }) +
            modalField({ id: 'modal-latin-honor', label: 'LATIN HONOR', type: 'select', icon: 'tag', optionsHtml: optionsHtml }) +
            modalField({ id: 'modal-graduation-date', label: 'DATE OF GRADUATION', type: 'date', icon: 'calendar', value: defaults.date || '' }) +
            modalField({ id: 'modal-graduation-year', label: 'YEAR GRADUATED', icon: 'hash', value: defaults.year || '' }) +
            '</div>';

        showModal({
            icon: modalHeaderIcon('pen'),
            title: 'EDIT HONOR GRADUATE RECORD',
            subtitle: 'Update this honor graduate’s saved record.',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: MODAL_BTN.secondary },
                {
                    label: 'SAVE',
                    icon: modalButtonIcon('check'),
                    className: MODAL_BTN.primary,
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

    function openDeleteModal(defaults) {
        var submitting = false;
        var bodyHtml =
            '<div class="space-y-4">' +
            '<p class="rounded-lg bg-gray-50 dark:bg-white/5 px-3 py-2 text-sm text-gray-700 dark:text-gray-300">PERMANENTLY DELETE <span class="font-medium text-gray-900 dark:text-white">' + escapeHtml(defaults.name) + '</span>? THIS CANNOT BE UNDONE.</p>' +
            modalField({ id: 'modal-delete-reason', label: 'REASON FOR REMOVAL', required: true, icon: 'pen', placeholder: 'E.G. DUPLICATE ENTRY', uppercase: true, note: 'PLAIN TEXT ONLY — NOT "N/A", "-", OR OTHER SPECIAL CHARACTERS.' }) +
            '</div>';

        showModal({
            icon: modalHeaderIcon('trash'),
            iconClassName: 'bg-csc-red-600/10 text-csc-red-600 dark:text-csc-red-400',
            title: 'DELETE HONOR GRADUATE RECORD',
            subtitle: 'State why this record is being removed. This will be recorded in the activity log.',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: MODAL_BTN.secondary },
                {
                    label: 'DELETE',
                    icon: modalButtonIcon('trash'),
                    className: MODAL_BTN.danger,
                    onClick: function (modalClose) {
                        if (submitting) {
                            return;
                        }
                        var reason = document.getElementById('modal-delete-reason').value;
                        if (!isValidRemovalReason(reason)) {
                            showToast('error', 'PLEASE ENTER A VALID REASON FOR REMOVAL.', 'PLAIN TEXT ONLY — NOT "N/A", "-", OR OTHER SPECIAL CHARACTERS.');
                            return;
                        }
                        submitting = true;
                        postJson(config.apiBase + '/api/delete_saved_graduate.php', {
                            csrf_token: config.csrfToken,
                            id: defaults.id,
                            reason: reason.trim(),
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
                                showToast('error', 'THE RECORD COULD NOT BE DELETED. PLEASE TRY AGAIN.');
                            });
                    },
                },
            ],
        });
    }

    function openApplyModal(defaults) {
        var bodyHtml =
            '<div class="space-y-4">' +
            modalField({ id: 'modal-applicant-name', label: 'NAME', type: 'static', value: defaults.name }) +
            modalField({ id: 'modal-application-date', label: 'DATE OF APPLICATION', required: true, type: 'date', icon: 'calendar', value: todayIsoDate() }) +
            '</div>';

        showModal({
            icon: modalHeaderIcon('check'),
            iconClassName: 'bg-green-500/10 text-green-600 dark:text-green-400',
            title: 'HONOR GRADUATE ELIGIBLE',
            subtitle: 'Record that this applicant’s Latin Honor has been filed.',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: MODAL_BTN.secondary },
                {
                    label: 'CONFIRM',
                    icon: modalButtonIcon('check'),
                    className: 'inline-flex items-center justify-center gap-1.5 rounded-xl bg-green-600 hover:bg-green-500 px-4 py-2.5 text-sm font-semibold text-white transition-colors',
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
            '<div class="text-left space-y-2 mb-4">' +
            '<div class="flex justify-between gap-4 border-b border-gray-100 dark:border-white/5 pb-1">' +
            '<span class="text-gray-500 dark:text-gray-400">SCHOOL</span>' +
            '<span class="text-gray-900 dark:text-white text-right">' + escapeHtml(values.schoolName) + '</span></div>' +
            '<div class="flex justify-between gap-4 border-b border-gray-100 dark:border-white/5 pb-1">' +
            '<span class="text-gray-500 dark:text-gray-400">YEAR</span>' +
            '<span class="text-gray-900 dark:text-white text-right">' + escapeHtml(values.year) + '</span></div>' +
            '<div class="flex justify-between gap-4 border-b border-gray-100 dark:border-white/5 pb-1">' +
            '<span class="text-gray-500 dark:text-gray-400">NEW DATE OF GRADUATION</span>' +
            '<span class="text-gray-900 dark:text-white text-right">' + escapeHtml(formatDisplayDate(values.date)) + '</span></div>' +
            '</div>' +
            '<p class="text-sm text-yellow-600 dark:text-yellow-400">THIS WILL OVERWRITE THE DATE OF GRADUATION FOR EVERY HONOR GRADUATE RECORD FROM THIS SCHOOL AND YEAR. THIS CANNOT BE UNDONE.</p>';

        showModal({
            icon: '<svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>',
            iconClassName: 'bg-yellow-500/10 text-yellow-600 dark:text-yellow-400',
            title: 'CONFIRM GRADUATION DATE UPDATE',
            bodyHtml: bodyHtml,
            buttons: [
                {
                    label: 'GO BACK',
                    className: 'flex-1 rounded-full border border-gray-300 dark:border-white/15 px-4 py-2.5 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 transition-colors',
                    onClick: function (close) {
                        close();
                        openBulkUpdateDateModal(values);
                    },
                },
                {
                    label: 'CONFIRM',
                    className: 'flex-1 rounded-full px-4 py-2.5 text-sm font-medium bg-csc-navy-600 hover:bg-csc-navy-500 text-white transition-colors',
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
            '<div class="space-y-4">' +
            modalField({ id: 'modal-bulk-school', label: 'SCHOOL NAME', type: 'select', icon: 'building', optionsHtml: buildSchoolOptionsHtml(prefill.schoolId) }) +
            modalField({ id: 'modal-bulk-year', label: 'YEAR', type: 'select', icon: 'calendar', attrs: ' disabled', optionsHtml: '<option value="" disabled selected>SELECT A SCHOOL FIRST</option>' }) +
            modalField({ id: 'modal-bulk-date', label: 'DATE OF GRADUATION', required: true, type: 'date', icon: 'calendar', value: prefill.date || '' }) +
            '</div>';

        showModal({
            icon: modalHeaderIcon('calendar'),
            title: 'UPDATE GRADUATION DATE',
            subtitle: 'Sets the date of graduation for every honor graduate record from the selected school and year.',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: MODAL_BTN.secondary },
                {
                    label: 'CONTINUE',
                    icon: modalButtonIcon('check'),
                    className: MODAL_BTN.primary,
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

    var clearFiltersBtn = document.getElementById('lookup-clear-filters-btn');
    if (clearFiltersBtn) {
        clearFiltersBtn.addEventListener('click', function () {
            document.getElementById('filter-school').value = '';
            document.getElementById('filter-year').value = '';
            document.getElementById('filter-name').value = '';
            performSearch(currentParams(1), true);
        });
    }

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

        var deleteBtn = event.target.closest('.delete-graduate-btn');
        if (deleteBtn) {
            openDeleteModal({
                id: deleteBtn.dataset.id,
                name: deleteBtn.dataset.name,
            });
            return;
        }

        var pageBtn = event.target.closest('.lookup-page-btn');
        if (pageBtn) {
            performSearch(currentParams(parseInt(pageBtn.dataset.page, 10)), true);
        }
    });

    resultsContainer.addEventListener('change', function (event) {
        var pageSelect = event.target.closest('.lookup-page-select');
        if (pageSelect) {
            performSearch(currentParams(parseInt(pageSelect.value, 10)), true);
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
