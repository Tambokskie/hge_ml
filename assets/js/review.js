document.addEventListener('DOMContentLoaded', function () {
    var config = window.HGE_REVIEW;
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

    function reload() {
        window.location.reload();
    }

    var viewPdfBtn = document.getElementById('view-pdf-btn');
    if (viewPdfBtn) {
        viewPdfBtn.addEventListener('click', function () {
            openPdfDrawer(viewPdfBtn.dataset.pdfUrl, viewPdfBtn.dataset.filename, config.pdfDetails);
        });
    }

    var filterNameInput = document.getElementById('review-filter-name');
    var filterCourseSelect = document.getElementById('review-filter-course');
    var filterEmptyMessage = document.getElementById('review-filter-empty');
    var rowsTbody = document.getElementById('review-rows-tbody');

    function applyRowFilter() {
        if (!rowsTbody) {
            return;
        }
        var nameQuery = (filterNameInput ? filterNameInput.value : '').trim().toUpperCase();
        var courseQuery = filterCourseSelect ? filterCourseSelect.value : '';
        var visibleCount = 0;

        rowsTbody.querySelectorAll('tr[data-filter-name]').forEach(function (row) {
            var matchesName = nameQuery === '' || row.dataset.filterName.indexOf(nameQuery) !== -1;
            var matchesCourse = courseQuery === '' || row.dataset.filterCourse === courseQuery;
            var visible = matchesName && matchesCourse;
            row.classList.toggle('hidden', !visible);
            if (visible) {
                visibleCount++;
            }
        });

        if (filterEmptyMessage) {
            filterEmptyMessage.classList.toggle('hidden', visibleCount !== 0);
        }
    }

    if (filterNameInput) {
        filterNameInput.addEventListener('input', applyRowFilter);
    }
    if (filterCourseSelect) {
        filterCourseSelect.addEventListener('change', applyRowFilter);
    }

    var massUpdateDateBtn = document.getElementById('mass-update-date-btn');
    if (massUpdateDateBtn) {
        massUpdateDateBtn.addEventListener('click', function () {
            var bodyHtml =
                '<div class="space-y-4">' +
                modalField({ id: 'modal-mass-date', label: 'DATE OF GRADUATION', required: true, type: 'date', icon: 'calendar' }) +
                '</div>';

            showModal({
                icon: modalHeaderIcon('calendar'),
                title: 'MASS UPDATE DATE OF GRADUATION',
                subtitle: 'Sets the date of graduation for every record in this table — not just the ones currently filtered/visible.',
                bodyHtml: bodyHtml,
                buttons: [
                    { label: 'CANCEL', className: MODAL_BTN.secondary },
                    {
                        label: 'CONFIRM',
                        icon: modalButtonIcon('check'),
                        className: MODAL_BTN.primary,
                        onClick: function (modalClose) {
                            var date = document.getElementById('modal-mass-date').value;
                            if (!date) {
                                showToast('error', 'PLEASE SELECT A DATE OF GRADUATION.');
                                return;
                            }
                            postJson(config.apiBase + '/api/mass_update_staged_date.php', {
                                csrf_token: config.csrfToken,
                                upload_id: config.uploadId,
                                graduation_date: date,
                            })
                                .then(function (data) {
                                    if (data.status === 'success') {
                                        showToast('success', data.message);
                                        modalClose();
                                        reload();
                                    } else {
                                        showToast('error', data.message);
                                    }
                                })
                                .catch(function () {
                                    showToast('error', 'THE RECORDS COULD NOT BE UPDATED. PLEASE TRY AGAIN.');
                                });
                        },
                    },
                ],
            });
        });
    }

    function openEditModal(rowIndex, defaults) {
        var isNew = rowIndex === null;
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
            icon: modalHeaderIcon(isNew ? 'plus' : 'academic'),
            title: isNew ? 'ADD RECORD' : 'EDIT RECORD',
            subtitle: isNew ? 'Add a new honor graduate record to this upload.' : 'Update this honor graduate record.',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: MODAL_BTN.secondary },
                {
                    label: 'SAVE',
                    icon: modalButtonIcon('check'),
                    className: MODAL_BTN.primary,
                    onClick: function (modalClose) {
                        var payload = {
                            csrf_token: config.csrfToken,
                            upload_id: config.uploadId,
                            graduate_name: document.getElementById('modal-graduate-name').value,
                            course: document.getElementById('modal-course').value,
                            latin_honor: document.getElementById('modal-latin-honor').value,
                            graduation_date: document.getElementById('modal-graduation-date').value,
                            graduation_year: document.getElementById('modal-graduation-year').value,
                        };
                        if (rowIndex !== null) {
                            payload.row_index = rowIndex;
                        }
                        postJson(config.apiBase + '/api/update_graduate.php', payload)
                            .then(function (data) {
                                if (data.status === 'success') {
                                    showToast('success', data.message);
                                    modalClose();
                                    reload();
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

    document.querySelectorAll('.edit-row-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openEditModal(parseInt(btn.dataset.rowIndex, 10), {
                name: btn.dataset.name,
                course: btn.dataset.course,
                honor: btn.dataset.honor,
                date: btn.dataset.date,
                year: btn.dataset.year,
            });
        });
    });

    var addBtn = document.getElementById('add-record-btn');
    if (addBtn) {
        addBtn.addEventListener('click', function () {
            openEditModal(null, {});
        });
    }

    document.querySelectorAll('.remove-row-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var rowIndex = parseInt(btn.dataset.rowIndex, 10);
            showModal({
                icon: '<svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>',
                iconClassName: 'bg-csc-red-600/10 text-csc-red-600 dark:text-csc-red-400',
                title: 'REMOVE THIS GRADUATE RECORD?',
                bodyHtml: '<p class="font-medium text-gray-900 dark:text-white">' + escapeHtml(btn.dataset.name) + '</p>',
                buttons: [
                    { label: 'CANCEL', className: 'flex-1 rounded-full border border-gray-300 dark:border-white/15 px-4 py-2.5 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 transition-colors' },
                    {
                        label: 'REMOVE RECORD',
                        className: 'flex-1 rounded-full px-4 py-2.5 text-sm font-medium bg-csc-red-600 hover:bg-csc-red-500 text-white transition-colors',
                        onClick: function (close) {
                            close();
                            postJson(config.apiBase + '/api/delete_graduate.php', {
                                csrf_token: config.csrfToken,
                                upload_id: config.uploadId,
                                row_index: rowIndex,
                            })
                                .then(function (data) {
                                    if (data.status === 'success') {
                                        showToast('success', data.message);
                                        reload();
                                    } else {
                                        showToast('error', data.message);
                                    }
                                })
                                .catch(function () {
                                    showToast('error', 'THE RECORD COULD NOT BE REMOVED. PLEASE TRY AGAIN.');
                                });
                        },
                    },
                ],
            });
        });
    });

    var selectAllCheckbox = document.getElementById('review-select-all');
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function () {
            document.querySelectorAll('.review-row-checkbox').forEach(function (checkbox) {
                checkbox.checked = selectAllCheckbox.checked;
            });
        });
    }

    if (rowsTbody) {
        rowsTbody.addEventListener('change', function (event) {
            if (event.target.classList.contains('review-row-checkbox') && !event.target.checked && selectAllCheckbox) {
                selectAllCheckbox.checked = false;
            }
        });
    }

    var multipleDeletionBtn = document.getElementById('multiple-deletion-btn');
    if (multipleDeletionBtn) {
        multipleDeletionBtn.addEventListener('click', function () {
            var checkedBoxes = Array.prototype.slice.call(document.querySelectorAll('.review-row-checkbox:checked'));

            if (checkedBoxes.length === 0) {
                showToast('error', 'PLEASE SELECT AT LEAST ONE RECORD TO REMOVE.');
                return;
            }

            var rowIndexes = checkedBoxes.map(function (checkbox) {
                return checkbox.dataset.rowIndex;
            });

            showModal({
                icon: '<svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>',
                iconClassName: 'bg-csc-red-600/10 text-csc-red-600 dark:text-csc-red-400',
                title: 'REMOVE ' + rowIndexes.length + ' RECORD(S)?',
                bodyHtml: '<p>THIS WILL PERMANENTLY REMOVE THE ' + rowIndexes.length + ' SELECTED RECORD(S) FROM THIS REVIEW. THIS CANNOT BE UNDONE.</p>',
                buttons: [
                    { label: 'CANCEL', className: 'flex-1 rounded-full border border-gray-300 dark:border-white/15 px-4 py-2.5 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 transition-colors' },
                    {
                        label: 'REMOVE RECORDS',
                        className: 'flex-1 rounded-full px-4 py-2.5 text-sm font-medium bg-csc-red-600 hover:bg-csc-red-500 text-white transition-colors',
                        onClick: function (close) {
                            close();
                            postJson(config.apiBase + '/api/delete_graduates.php', {
                                csrf_token: config.csrfToken,
                                upload_id: config.uploadId,
                                row_indexes: rowIndexes.join(','),
                            })
                                .then(function (data) {
                                    if (data.status === 'success') {
                                        showToast('success', data.message);
                                        reload();
                                    } else {
                                        showToast('error', data.message);
                                    }
                                })
                                .catch(function () {
                                    showToast('error', 'THE RECORDS COULD NOT BE REMOVED. PLEASE TRY AGAIN.');
                                });
                        },
                    },
                ],
            });
        });
    }

    var saveBtn = document.getElementById('save-records-btn');
    if (saveBtn) {
        saveBtn.addEventListener('click', function () {
            var recordCount = rowsTbody ? rowsTbody.querySelectorAll('tr[data-filter-name]').length : 0;

            showModal({
                icon: '<svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>',
                iconClassName: 'bg-green-500/10 text-green-600 dark:text-green-400',
                title: 'CONFIRM SAVE RECORDS',
                bodyHtml: '<p>THIS WILL SAVE ' + recordCount + ' RECORD(S) AS OFFICIAL HONOR GRADUATE RECORDS FOR THIS SCHOOL AND YEAR. THIS CANNOT BE UNDONE FROM THIS PAGE. CONTINUE?</p>',
                buttons: [
                    { label: 'CANCEL', className: 'flex-1 rounded-full border border-gray-300 dark:border-white/15 px-4 py-2.5 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 transition-colors' },
                    {
                        label: 'CONFIRM',
                        className: 'flex-1 rounded-full px-4 py-2.5 text-sm font-medium bg-green-600 hover:bg-green-500 text-white transition-colors',
                        onClick: function (modalClose) {
                            modalClose();
                            postJson(config.apiBase + '/api/save_records.php', {
                                csrf_token: config.csrfToken,
                                upload_id: config.uploadId,
                            })
                                .then(function (data) {
                                    if (data.status === 'success') {
                                        showToast('success', data.message);
                                        window.location.href = config.viewUploadUrl;
                                    } else {
                                        showToast('error', data.message);
                                    }
                                })
                                .catch(function () {
                                    showToast('error', 'RECORDS COULD NOT BE SAVED. PLEASE TRY AGAIN.');
                                });
                        },
                    },
                ],
            });
        });
    }

    var saveDraftBtn = document.getElementById('save-draft-btn');
    if (saveDraftBtn) {
        saveDraftBtn.addEventListener('click', function () {
            saveDraftBtn.disabled = true;
            postJson(config.apiBase + '/api/save_draft.php', {
                csrf_token: config.csrfToken,
                upload_id: config.uploadId,
            })
                .then(function (data) {
                    if (data.status === 'success') {
                        showToast('success', data.message);
                        window.location.href = data.redirect_url || config.viewUploadUrl;
                    } else {
                        saveDraftBtn.disabled = false;
                        showToast('error', data.message);
                    }
                })
                .catch(function () {
                    saveDraftBtn.disabled = false;
                    showToast('error', 'THE DRAFT COULD NOT BE SAVED. PLEASE TRY AGAIN.');
                });
        });
    }
});
