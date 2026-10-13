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
        allowNavigation();
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

    // Warns before leaving this page while there is staged review data that
    // has not gone through SAVE RECORDS or SAVE AS DRAFT yet — those are the
    // only writes to the official/draft tables; everything else lives only
    // in $_SESSION['extraction_review'] until then. allowNavigation() is
    // called right before our own intentional navigations (reload() after a
    // successful edit, the SAVE RECORDS / SAVE AS DRAFT redirects, and the
    // CANCEL form submit) so those never trigger the prompt they'd otherwise
    // cause as ordinary navigations.
    var navigationGuardEnabled = !!(rowsTbody && rowsTbody.querySelector('tr[data-filter-name]'));

    function allowNavigation() {
        navigationGuardEnabled = false;
    }

    window.addEventListener('beforeunload', function (event) {
        if (!navigationGuardEnabled) {
            return;
        }
        event.preventDefault();
        event.returnValue = '';
    });

    document.addEventListener('click', function (event) {
        if (!navigationGuardEnabled) {
            return;
        }

        var link = event.target.closest('a[href]');
        if (!link || link.target === '_blank' || link.hasAttribute('download')) {
            return;
        }

        var href = link.getAttribute('href') || '';
        if (href === '' || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) {
            return;
        }

        event.preventDefault();
        showModal({
            icon: modalHeaderIcon('warning'),
            iconClassName: 'bg-yellow-500/10 text-yellow-600 dark:text-yellow-400',
            title: 'LEAVE WITHOUT SAVING?',
            bodyHtml: '<p>YOU ARE STILL REVIEWING THIS UPLOAD\'S EXTRACTED RECORDS. IF YOU LEAVE NOW WITHOUT CLICKING SAVE RECORDS OR SAVE AS DRAFT, YOUR CHANGES WILL NOT BE SAVED.</p>',
            buttons: [
                { label: 'NO, STAY ON THIS PAGE', className: 'flex-1 rounded-full border border-gray-300 dark:border-white/15 px-4 py-2.5 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 transition-colors' },
                {
                    label: 'YES, LEAVE',
                    className: 'flex-1 rounded-full px-4 py-2.5 text-sm font-medium bg-csc-red-600 hover:bg-csc-red-500 text-white transition-colors',
                    onClick: function (close) {
                        close();
                        allowNavigation();
                        window.location.href = link.href;
                    },
                },
            ],
        });
    });

    var cancelForm = document.querySelector('form input[name="cancel"]');
    cancelForm = cancelForm ? cancelForm.closest('form') : null;
    if (cancelForm) {
        cancelForm.addEventListener('submit', function () {
            allowNavigation();
        });
    }

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
            modalField({ id: 'modal-graduate-name', label: 'GRADUATE NAME', required: true, icon: 'user', value: defaults.name || '', uppercase: true }) +
            modalField({ id: 'modal-course', label: 'DEGREE/COURSE', icon: 'academic', value: defaults.course || '', uppercase: true }) +
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
            var submitting = false;
            var rowIndex = parseInt(btn.dataset.rowIndex, 10);
            var isDuplicate = btn.dataset.duplicate === '1';
            var duplicateNoteHtml = isDuplicate
                ? '<p class="rounded-lg bg-orange-500/10 border border-orange-500/30 px-3 py-2 text-sm text-orange-700 dark:text-orange-400">⚠ THIS NAME ALREADY EXISTS ON FILE, FROM <span class="font-medium">' + escapeHtml(btn.dataset.duplicateFile) + '</span> (UPLOADED ' + escapeHtml(btn.dataset.duplicateDate) + ').</p>'
                : '';

            var bodyHtml =
                '<div class="space-y-4">' +
                duplicateNoteHtml +
                '<p class="rounded-lg bg-gray-50 dark:bg-white/5 px-3 py-2 text-sm text-gray-700 dark:text-gray-300">PERMANENTLY REMOVE <span class="font-medium text-gray-900 dark:text-white">' + escapeHtml(btn.dataset.name) + '</span>? THIS CANNOT BE UNDONE.</p>' +
                modalField({ id: 'modal-remove-reason', label: 'REASON FOR REMOVAL', required: true, icon: 'pen', placeholder: 'E.G. DUPLICATE ENTRY', uppercase: true, value: isDuplicate ? 'DUPLICATE OF EXISTING RECORD' : '', note: 'PLAIN TEXT ONLY — NOT "N/A", "-", OR OTHER SPECIAL CHARACTERS.' }) +
                '</div>';

            showModal({
                icon: modalHeaderIcon('trash'),
                iconClassName: 'bg-csc-red-600/10 text-csc-red-600 dark:text-csc-red-400',
                title: 'REMOVE THIS GRADUATE RECORD?',
                subtitle: 'State why this record is being removed. This will be recorded in the activity log.',
                bodyHtml: bodyHtml,
                buttons: [
                    { label: 'CANCEL', className: MODAL_BTN.secondary },
                    {
                        label: 'REMOVE RECORD',
                        icon: modalButtonIcon('trash'),
                        className: MODAL_BTN.danger,
                        onClick: function (modalClose) {
                            if (submitting) {
                                return;
                            }
                            var reason = document.getElementById('modal-remove-reason').value;
                            if (!isValidRemovalReason(reason)) {
                                showToast('error', 'PLEASE ENTER A VALID REASON FOR REMOVAL.', 'PLAIN TEXT ONLY — NOT "N/A", "-", OR OTHER SPECIAL CHARACTERS.');
                                return;
                            }
                            submitting = true;
                            postJson(config.apiBase + '/api/delete_graduate.php', {
                                csrf_token: config.csrfToken,
                                upload_id: config.uploadId,
                                row_index: rowIndex,
                                reason: reason.trim(),
                            })
                                .then(function (data) {
                                    if (data.status === 'success') {
                                        showToast('success', data.message);
                                        modalClose();
                                        reload();
                                    } else {
                                        submitting = false;
                                        showToast('error', data.message);
                                    }
                                })
                                .catch(function () {
                                    submitting = false;
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
                if (!checkbox.disabled) {
                    checkbox.checked = selectAllCheckbox.checked;
                }
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
            var allDuplicates = checkedBoxes.every(function (checkbox) {
                return checkbox.dataset.duplicate === '1';
            });
            var someDuplicates = !allDuplicates && checkedBoxes.some(function (checkbox) {
                return checkbox.dataset.duplicate === '1';
            });

            var submitting = false;
            var duplicateNoteHtml = '';
            if (allDuplicates) {
                duplicateNoteHtml = '<p class="rounded-lg bg-orange-500/10 border border-orange-500/30 px-3 py-2 text-sm text-orange-700 dark:text-orange-400">⚠ ALL SELECTED RECORDS ALREADY EXIST ON FILE UNDER A DIFFERENT UPLOAD.</p>';
            } else if (someDuplicates) {
                duplicateNoteHtml = '<p class="rounded-lg bg-orange-500/10 border border-orange-500/30 px-3 py-2 text-sm text-orange-700 dark:text-orange-400">⚠ SOME SELECTED RECORDS ALREADY EXIST ON FILE UNDER A DIFFERENT UPLOAD — CHECK THE ⚠ BADGE IN THE TABLE BEFORE CONFIRMING.</p>';
            }
            var bodyHtml =
                '<div class="space-y-4">' +
                duplicateNoteHtml +
                '<p class="rounded-lg bg-gray-50 dark:bg-white/5 px-3 py-2 text-sm text-gray-700 dark:text-gray-300">THIS WILL PERMANENTLY REMOVE THE ' + rowIndexes.length + ' SELECTED RECORD(S) FROM THIS REVIEW. THIS CANNOT BE UNDONE.</p>' +
                modalField({ id: 'modal-bulk-remove-reason', label: 'REASON FOR REMOVAL', required: true, icon: 'pen', placeholder: 'E.G. DUPLICATE ENTRIES', uppercase: true, value: allDuplicates ? 'DUPLICATE OF EXISTING RECORDS' : '', note: 'PLAIN TEXT ONLY — NOT "N/A", "-", OR OTHER SPECIAL CHARACTERS.' }) +
                '</div>';

            showModal({
                icon: modalHeaderIcon('trash'),
                iconClassName: 'bg-csc-red-600/10 text-csc-red-600 dark:text-csc-red-400',
                title: 'REMOVE ' + rowIndexes.length + ' RECORD(S)?',
                subtitle: 'State why these records are being removed. This will be recorded in the activity log.',
                bodyHtml: bodyHtml,
                buttons: [
                    { label: 'CANCEL', className: MODAL_BTN.secondary },
                    {
                        label: 'REMOVE RECORDS',
                        icon: modalButtonIcon('trash'),
                        className: MODAL_BTN.danger,
                        onClick: function (modalClose) {
                            if (submitting) {
                                return;
                            }
                            var reason = document.getElementById('modal-bulk-remove-reason').value;
                            if (!isValidRemovalReason(reason)) {
                                showToast('error', 'PLEASE ENTER A VALID REASON FOR REMOVAL.', 'PLAIN TEXT ONLY — NOT "N/A", "-", OR OTHER SPECIAL CHARACTERS.');
                                return;
                            }
                            submitting = true;
                            postJson(config.apiBase + '/api/delete_graduates.php', {
                                csrf_token: config.csrfToken,
                                upload_id: config.uploadId,
                                row_indexes: rowIndexes.join(','),
                                reason: reason.trim(),
                            })
                                .then(function (data) {
                                    if (data.status === 'success') {
                                        showToast('success', data.message);
                                        modalClose();
                                        reload();
                                    } else {
                                        submitting = false;
                                        showToast('error', data.message);
                                    }
                                })
                                .catch(function () {
                                    submitting = false;
                                    showToast('error', 'THE RECORDS COULD NOT BE REMOVED. PLEASE TRY AGAIN.');
                                });
                        },
                    },
                ],
            });
        });
    }

    // UPLOAD EXCEL: adds graduates from a spreadsheet to this review as
    // MANUAL rows — the quick fix when the AI/OCR misread a list. Nothing is
    // saved until SAVE RECORDS.
    var importExcelBtn = document.getElementById('import-excel-btn');

    function importSkippedHtml(data) {
        var items = (data.skipped || []).slice(0, 15).map(function (item) {
            return '<li>ROW ' + item.row + ': ' + escapeHtml(item.reason) + '</li>';
        }).join('');
        var more = data.skipped_count > 15 ? '<li>…AND ' + (data.skipped_count - 15) + ' MORE.</li>' : '';

        return '<ul class="mt-2 max-h-48 list-disc space-y-0.5 overflow-y-auto pl-5 text-sm text-gray-700 dark:text-gray-300">' + items + more + '</ul>';
    }

    function showImportResult(data) {
        var notes = [];
        if (data.undated > 0) {
            notes.push('<p>' + data.undated + ' ROW(S) HAVE NO READABLE DATE OF GRADUATION. THEY WERE ADDED WITHOUT A DATE — FIX THEM IN THE TABLE OR USE MASS UPDATE DATE.</p>');
        }
        if (data.skipped_count > 0) {
            notes.push('<p>' + data.skipped_count + ' ROW(S) WERE NOT ADDED:</p>' + importSkippedHtml(data));
        }

        showModal({
            icon: modalHeaderIcon('check'),
            iconClassName: 'bg-yellow-500/10 text-yellow-600 dark:text-yellow-400',
            title: data.added + ' RECORD(S) ADDED',
            subtitle: 'They are marked MANUAL and are not saved until you click SAVE RECORDS.',
            bodyHtml: '<div class="space-y-3 text-sm text-gray-700 dark:text-gray-300">' + notes.join('') + '</div>',
            buttons: [
                { label: 'OK', className: MODAL_BTN.primary, onClick: function (modalClose) { modalClose(); reload(); } },
            ],
        });
    }

    if (importExcelBtn) {
        importExcelBtn.addEventListener('click', function () {
            var submitting = false;

            showModal({
                icon: modalHeaderIcon('document'),
                title: 'UPLOAD EXCEL',
                subtitle: 'Add graduates from a spreadsheet to this review. They are marked MANUAL.',
                bodyHtml: '<div class="space-y-4">' +
                    '<p class="rounded-lg bg-gray-50 dark:bg-white/5 px-3 py-2 text-sm text-gray-700 dark:text-gray-300">THE FIRST ROW MUST HAVE THESE HEADERS: <strong>NAME OF HONOR GRADUATE</strong>, <strong>COURSE</strong>, <strong>HONORS RECEIVED</strong> (CUM LAUDE, MAGNA CUM LAUDE OR SUMMA CUM LAUDE) AND <strong>DATE OF GRADUATION</strong>. ONE GRADUATE PER ROW. THE ROWS ARE ADDED TO THE ONES ALREADY IN THIS REVIEW. <a href="' + config.apiBase + '/api/download_review_import_template.php" download class="font-semibold text-csc-navy-600 dark:text-csc-navy-300 underline underline-offset-2">DOWNLOAD THE TEMPLATE</a></p>' +
                    '<div><label class="mb-1.5 block text-xs font-bold tracking-wide text-csc-navy-600 dark:text-csc-navy-300" for="modal-import-file">EXCEL FILE <span class="text-csc-red-600">*</span></label>' +
                    '<input id="modal-import-file" type="file" accept=".xlsx,.xls,.csv" class="block w-full text-sm text-gray-700 dark:text-gray-300 file:mr-3 file:cursor-pointer file:rounded-xl file:border file:border-gray-300 dark:file:border-white/15 file:bg-white dark:file:bg-surface-800 file:px-4 file:py-2 file:text-sm file:font-medium file:text-gray-700 dark:file:text-gray-300">' +
                    '<p class="mt-1 text-xs text-gray-500 dark:text-gray-500">.XLSX, .XLS OR .CSV, UP TO 10 MB.</p></div>' +
                    '</div>',
                buttons: [
                    { label: 'CANCEL', className: MODAL_BTN.secondary },
                    {
                        label: 'UPLOAD',
                        icon: modalButtonIcon('check'),
                        className: MODAL_BTN.primary,
                        onClick: function (modalClose) {
                            if (submitting) {
                                return;
                            }
                            var fileInput = document.getElementById('modal-import-file');
                            if (!fileInput.files || fileInput.files.length === 0) {
                                showToast('error', 'PLEASE CHOOSE AN EXCEL FILE TO UPLOAD.');
                                return;
                            }
                            submitting = true;
                            modalClose();
                            var closeLoading = showLoadingModal('READING FILE', 'Please wait while the rows are read. Nothing is saved yet.');
                            postJson(config.apiBase + '/api/import_review_rows.php', {
                                csrf_token: config.csrfToken,
                                upload_id: config.uploadId,
                                file: fileInput.files[0],
                            })
                                .then(function (data) {
                                    closeLoading();
                                    if (data.status === 'success') {
                                        if (data.skipped_count > 0 || data.undated > 0) {
                                            showImportResult(data);
                                        } else {
                                            showToast('success', data.message);
                                            reload();
                                        }
                                    } else if (data.skipped_count > 0) {
                                        showModal({
                                            icon: modalHeaderIcon('warning'),
                                            iconClassName: 'bg-yellow-500/10 text-yellow-600 dark:text-yellow-400',
                                            title: 'NO ROWS COULD BE ADDED',
                                            subtitle: 'Fix the file and upload it again.',
                                            bodyHtml: '<div class="text-sm text-gray-700 dark:text-gray-300"><p>' + data.skipped_count + ' ROW(S) WERE NOT ADDED:</p>' + importSkippedHtml(data) + '</div>',
                                            buttons: [{ label: 'OK', className: MODAL_BTN.primary }],
                                        });
                                    } else {
                                        showToast('error', data.message);
                                    }
                                })
                                .catch(function () {
                                    closeLoading();
                                    showToast('error', 'THE FILE COULD NOT BE UPLOADED. PLEASE TRY AGAIN.');
                                });
                        },
                    },
                ],
            });
        });
    }

    var saveBtn = document.getElementById('save-records-btn');

    function duplicateGroupHtml(group) {
        // A saved copy that already has a filed application PREVAILS over the
        // copies in this review, so those are pre-ticked for removal.
        var prevails = (group.saved || []).some(function (saved) { return saved.has_applied; });
        var stagedHtml = group.staged.map(function (row) {
            var details = [row.course || '—', row.latin_honor, row.graduation_year].filter(Boolean).join(' · ');
            return '<label class="flex items-start gap-2 py-1 cursor-pointer">' +
                '<input type="checkbox" class="dup-remove-checkbox mt-0.5 h-4 w-4 rounded border-gray-300 dark:border-white/20 text-csc-navy-600" data-row-index="' + row.row_index + '"' + (prevails ? ' checked' : '') + '>' +
                '<span><span class="font-semibold">NO. ' + row.row_number + '</span> — ' + escapeHtml(details) + '</span></label>';
        }).join('');

        // Saved copies are matched by name + course at ANY school and year, so each
        // note names its school and year; they belong to that school's list.
        var savedHtml = (group.saved || []).map(function (saved) {
            var savedDetails = [saved.course || '—', saved.latin_honor, saved.graduation_year].filter(Boolean).join(' · ');
            var appliedTag = saved.has_applied
                ? ' <strong>— APPLIED ' + escapeHtml(saved.applied_on_label || '') + ': THIS RECORD PREVAILS</strong>'
                : '';
            return '<p class="mt-1 rounded-md ' + (saved.has_applied ? 'bg-green-500/10 text-green-700 dark:text-green-400' : 'bg-orange-500/10 text-orange-700 dark:text-orange-400') + ' px-2 py-1 text-xs">' +
                'ALREADY SAVED AT ' + escapeHtml(saved.school || '—') + ' — ' + escapeHtml(saved.graduate_name) + ' · ' + escapeHtml(savedDetails) +
                ' (FROM ' + escapeHtml(saved.original_filename) + ', UPLOADED ' + escapeHtml(saved.uploaded_on) + ')' + appliedTag + '</p>';
        }).join('');

        return '<div class="rounded-lg border border-gray-200 dark:border-white/10 px-3 py-2">' +
            '<p class="text-sm font-bold text-gray-900 dark:text-white uppercase">' + escapeHtml(group.name) + '</p>' +
            stagedHtml + savedHtml + '</div>';
    }

    // Shown by SAVE RECORDS when the same name appears more than once in this
    // review, or already exists on file for this school and year or at another school. The admin
    // removes the unwanted staged rows, saves anyway (two different people can
    // share a name), or cancels back to the review view — nothing is saved by
    // this modal itself.
    function openDuplicatesModal(groups) {
        var submitting = false;
        var stagedCount = groups.reduce(function (sum, group) { return sum + group.staged.length; }, 0);

        showModal({
            widthClass: 'max-w-2xl',
            icon: modalHeaderIcon('warning'),
            iconClassName: 'bg-yellow-500/10 text-yellow-600 dark:text-yellow-400',
            title: 'POSSIBLE DUPLICATE NAMES FOUND',
            subtitle: groups.length + ' name(s) appear more than once or already exist on file, at this or another school. Nothing has been saved yet.',
            bodyHtml: '<div class="space-y-4">' +
                '<p class="rounded-lg bg-gray-50 dark:bg-white/5 px-3 py-2 text-sm text-gray-700 dark:text-gray-300">TICK THE RECORDS FROM THIS REVIEW THAT SHOULD BE REMOVED, OR SAVE ANYWAY IF THEY ARE DIFFERENT PEOPLE. A SAVED RECORD THAT ALREADY HAS AN APPLICATION PREVAILS, SO ITS DUPLICATES HERE ARE PRE-TICKED. CANCEL TO GO BACK TO THE REVIEW.</p>' +
                '<div class="max-h-72 space-y-2 overflow-y-auto pr-1">' + groups.map(duplicateGroupHtml).join('') + '</div>' +
                modalField({ id: 'modal-dup-remove-reason', label: 'REASON FOR REMOVAL', optional: true, icon: 'pen', placeholder: 'E.G. DUPLICATE ENTRIES', uppercase: true, value: 'DUPLICATE ENTRY', note: 'ONLY NEEDED WHEN REMOVING RECORDS. PLAIN TEXT ONLY — NOT "N/A", "-", OR OTHER SPECIAL CHARACTERS.' }) +
                '</div>',
            buttons: [
                { label: 'CANCEL', className: MODAL_BTN.secondary },
                {
                    label: 'SAVE ANYWAY',
                    className: MODAL_BTN.secondary,
                    onClick: function (modalClose) {
                        modalClose();
                        openSaveConfirm();
                    },
                },
                {
                    label: 'REMOVE SELECTED',
                    icon: modalButtonIcon('trash'),
                    className: MODAL_BTN.danger,
                    onClick: function (modalClose) {
                        if (submitting) {
                            return;
                        }
                        var rowIndexes = Array.prototype.slice.call(document.querySelectorAll('.dup-remove-checkbox:checked')).map(function (checkbox) {
                            return checkbox.dataset.rowIndex;
                        });
                        if (rowIndexes.length === 0) {
                            showToast('error', 'TICK AT LEAST ONE RECORD TO REMOVE (OF ' + stagedCount + ' LISTED).');
                            return;
                        }
                        var reason = document.getElementById('modal-dup-remove-reason').value;
                        if (!isValidRemovalReason(reason)) {
                            showToast('error', 'PLEASE ENTER A VALID REASON FOR REMOVAL.', 'PLAIN TEXT ONLY — NOT "N/A", "-", OR OTHER SPECIAL CHARACTERS.');
                            return;
                        }
                        submitting = true;
                        postJson(config.apiBase + '/api/delete_graduates.php', {
                            csrf_token: config.csrfToken,
                            upload_id: config.uploadId,
                            row_indexes: rowIndexes.join(','),
                            reason: reason.trim(),
                        })
                            .then(function (data) {
                                if (data.status === 'success') {
                                    showToast('success', data.message + ' CLICK SAVE RECORDS AGAIN WHEN READY.');
                                    modalClose();
                                    reload();
                                } else {
                                    submitting = false;
                                    showToast('error', data.message);
                                }
                            })
                            .catch(function () {
                                submitting = false;
                                showToast('error', 'THE RECORDS COULD NOT BE REMOVED. PLEASE TRY AGAIN.');
                            });
                    },
                },
            ],
        });
    }

    function openSaveConfirm() {
        var recordCount = rowsTbody ? rowsTbody.querySelectorAll('tr[data-filter-name]').length : 0;

        showModal({
            icon: modalHeaderIcon('check'),
            iconClassName: 'bg-green-500/10 text-green-600 dark:text-green-400',
            title: 'CONFIRM SAVE RECORDS',
            subtitle: 'Saving finalizes this upload’s staged records.',
            bodyHtml: '<p class="rounded-lg bg-gray-50 dark:bg-white/5 px-3 py-2 text-sm text-gray-700 dark:text-gray-300">THIS WILL SAVE ' + recordCount + ' RECORD(S) AS OFFICIAL HONOR GRADUATE RECORDS FOR THIS SCHOOL AND YEAR. THIS CANNOT BE UNDONE FROM THIS PAGE.</p>',
            buttons: [
                { label: 'CANCEL', className: MODAL_BTN.secondary },
                {
                    label: 'CONFIRM',
                    icon: modalButtonIcon('check'),
                    className: 'inline-flex items-center justify-center gap-1.5 rounded-xl bg-green-600 hover:bg-green-500 px-4 py-2.5 text-sm font-semibold text-white transition-colors',
                    onClick: function (modalClose) {
                        modalClose();
                        postJson(config.apiBase + '/api/save_records.php', {
                            csrf_token: config.csrfToken,
                            upload_id: config.uploadId,
                        })
                            .then(function (data) {
                                if (data.status === 'success') {
                                    allowNavigation();
                                    showToast('success', data.message);

                                    if (data.duplicate_count > 0) {
                                        showModal({
                                            icon: modalHeaderIcon('check'),
                                            iconClassName: 'bg-yellow-500/10 text-yellow-600 dark:text-yellow-400',
                                            title: 'POSSIBLE DUPLICATES FOUND',
                                            subtitle: 'The records were saved.',
                                            bodyHtml: '<p class="rounded-lg bg-gray-50 dark:bg-white/5 px-3 py-2 text-sm text-gray-700 dark:text-gray-300"><span class="font-medium text-gray-900 dark:text-white">' + data.duplicate_count + '</span> DUPLICATE GROUP(S) INVOLVING THIS SCHOOL WERE DETECTED. REVIEW THEM NOW OR LATER FROM LOOK-UP.</p>',
                                            buttons: [
                                                {
                                                    label: 'CONTINUE',
                                                    className: MODAL_BTN.secondary,
                                                    onClick: function () { window.location.href = config.viewUploadUrl; },
                                                },
                                                {
                                                    label: 'REVIEW DUPLICATES',
                                                    className: MODAL_BTN.primary,
                                                    onClick: function () { window.location.href = config.apiBase + '/admin/duplicates.php?school_id=' + encodeURIComponent(data.school_id); },
                                                },
                                            ],
                                        });
                                        return;
                                    }

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
    }

    if (saveBtn) {
        saveBtn.addEventListener('click', function () {
            if (saveBtn.disabled) {
                return;
            }
            saveBtn.disabled = true;
            postJson(config.apiBase + '/api/check_save_duplicates.php', {
                csrf_token: config.csrfToken,
                upload_id: config.uploadId,
            })
                .then(function (data) {
                    saveBtn.disabled = false;
                    if (data.status !== 'success') {
                        showToast('error', data.message);
                    } else if (data.groups.length > 0) {
                        openDuplicatesModal(data.groups);
                    } else {
                        openSaveConfirm();
                    }
                })
                .catch(function () {
                    saveBtn.disabled = false;
                    showToast('error', 'THE DUPLICATE CHECK COULD NOT BE COMPLETED. PLEASE TRY AGAIN.');
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
                        allowNavigation();
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
