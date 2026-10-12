document.addEventListener('DOMContentLoaded', function () {
    var config = window.HGE_ADMIN;
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
    function openBackupModal() {
        var submitting = false;
        var bodyHtml =
            '<div class="space-y-4">' +
            modalField({ id: 'modal-backup-username', label: 'USERNAME', required: true, icon: 'user' }) +
            modalField({ id: 'modal-backup-password', label: 'PASSWORD', required: true, type: 'password', icon: 'lock' }) +
            '</div>';

        showModal({
            icon: modalHeaderIcon('lock'),
            title: 'CONFIRM ADMINISTRATOR IDENTITY',
            subtitle: 'For security, re-enter your credentials to download a full database backup.',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: MODAL_BTN.secondary },
                {
                    label: 'CREATE DB BACKUP',
                    icon: modalButtonIcon('download'),
                    className: MODAL_BTN.primary,
                    onClick: function (modalClose) {
                        if (submitting) {
                            return;
                        }
                        submitting = true;

                        var formData = new FormData();
                        formData.set('csrf_token', config.csrfToken);
                        formData.set('username', document.getElementById('modal-backup-username').value);
                        formData.set('password', document.getElementById('modal-backup-password').value);

                        fetch(config.apiBase + '/api/backup_database.php', { method: 'POST', body: formData })
                            .then(function (response) {
                                var contentType = response.headers.get('Content-Type') || '';
                                if (contentType.indexOf('application/json') !== -1) {
                                    return response.json().then(function (data) {
                                        throw new Error(data.message || 'THE BACKUP COULD NOT BE CREATED.');
                                    });
                                }

                                var filename = 'hge_masterlist_backup.sql';
                                var disposition = response.headers.get('Content-Disposition') || '';
                                var match = disposition.match(/filename="?([^"]+)"?/);
                                if (match) {
                                    filename = match[1];
                                }

                                return response.blob().then(function (blob) {
                                    return { blob: blob, filename: filename };
                                });
                            })
                            .then(function (result) {
                                var url = URL.createObjectURL(result.blob);
                                var link = document.createElement('a');
                                link.href = url;
                                link.download = result.filename;
                                document.body.appendChild(link);
                                link.click();
                                link.remove();
                                URL.revokeObjectURL(url);

                                showToast('success', 'DATABASE BACKUP DOWNLOADED.');
                                modalClose();
                            })
                            .catch(function (err) {
                                submitting = false;
                                showToast('error', err.message || 'THE BACKUP COULD NOT BE CREATED. PLEASE TRY AGAIN.');
                            });
                    },
                },
            ],
        });
    }

    // Step 1: a credentials-only prompt, verified server-side before the
    // actual key-configuration modal ever loads — so a shoulder-surfed or
    // left-open Users page doesn't expose which AI keys are configured
    // without first re-authenticating.
    function openAiSettingsCredentialModal() {
        var submitting = false;
        var bodyHtml =
            '<div class="space-y-4">' +
            modalField({ id: 'modal-ai-verify-username', label: 'USERNAME', required: true, icon: 'user' }) +
            modalField({ id: 'modal-ai-verify-password', label: 'PASSWORD', required: true, type: 'password', icon: 'lock' }) +
            '</div>';

        showModal({
            icon: modalHeaderIcon('lock'),
            title: 'CONFIRM ADMINISTRATOR IDENTITY',
            subtitle: 'For security, re-enter your credentials to view or change the AI extraction API keys.',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: MODAL_BTN.secondary },
                {
                    label: 'CONTINUE',
                    icon: modalButtonIcon('check'),
                    className: MODAL_BTN.primary,
                    onClick: function (modalClose) {
                        if (submitting) {
                            return;
                        }
                        submitting = true;

                        var username = document.getElementById('modal-ai-verify-username').value;
                        var password = document.getElementById('modal-ai-verify-password').value;

                        postJson(config.apiBase + '/api/verify_administrator.php', {
                            csrf_token: config.csrfToken,
                            username: username,
                            password: password,
                        })
                            .then(function (data) {
                                if (data.status === 'success') {
                                    modalClose();
                                    openAiSettingsModal(username, password);
                                } else {
                                    submitting = false;
                                    showToast('error', data.message);
                                }
                            })
                            .catch(function () {
                                submitting = false;
                                showToast('error', 'IDENTITY COULD NOT BE VERIFIED. PLEASE TRY AGAIN.');
                            });
                    },
                },
            ],
        });
    }

    // Step 2: the actual key-configuration modal, only reachable after
    // openAiSettingsCredentialModal() above has verified the admin's
    // credentials. Those same credentials ride along with the final save so
    // api/update_gemini_keys.php can independently re-verify them too,
    // rather than trusting a client-side "already verified" flag.
    function openAiSettingsModal(verifiedUsername, verifiedPassword) {
        var submitting = false;
        var status = config.geminiKeyStatus || {};
        var key1Placeholder = status.key1 && status.key1.configured ? 'CURRENT: ' + status.key1.masked : 'NOT CONFIGURED';
        var key2Placeholder = status.key2 && status.key2.configured ? 'CURRENT: ' + status.key2.masked : 'NOT CONFIGURED';
        var key3Placeholder = status.key3 && status.key3.configured ? 'CURRENT: ' + status.key3.masked : 'NOT CONFIGURED';
        var key4Placeholder = status.key4 && status.key4.configured ? 'CURRENT: ' + status.key4.masked : 'NOT CONFIGURED';

        var bodyHtml =
            '<div class="space-y-4">' +
            '<p class="rounded-lg bg-gray-50 dark:bg-white/5 px-3 py-2 text-sm text-gray-700 dark:text-gray-300">CONFIGURE THE GEMINI API KEY(S) USED FOR AI-BASED PDF EXTRACTION. THE SAVED VALUE TAKES EFFECT IMMEDIATELY — NO SERVER RESTART NEEDED.</p>' +
            modalField({ id: 'modal-ai-key-1', label: 'GEMINI API KEY', optional: true, type: 'password', icon: 'lock', placeholder: key1Placeholder, note: 'Leave blank to keep the current value.' }) +
            modalField({ id: 'modal-ai-key-2', label: 'GEMINI API KEY 2 (QUOTA FALLBACK)', optional: true, type: 'password', icon: 'lock', placeholder: key2Placeholder, note: 'Leave blank to keep the current value.' }) +
            modalField({ id: 'modal-ai-key-3', label: 'GEMINI API KEY 3 (QUOTA FALLBACK)', optional: true, type: 'password', icon: 'lock', placeholder: key3Placeholder, note: 'Leave blank to keep the current value.' }) +
            modalField({ id: 'modal-ai-key-4', label: 'GEMINI API KEY 4 (QUOTA FALLBACK)', optional: true, type: 'password', icon: 'lock', placeholder: key4Placeholder, note: 'Leave blank to keep the current value.' }) +
            '</div>';

        showModal({
            icon: modalHeaderIcon('lock'),
            title: 'AI EXTRACTION SETTINGS',
            subtitle: 'Identity confirmed. Leave a field blank to keep its current value.',
            bodyHtml: bodyHtml,
            buttons: [
                { label: 'CANCEL', className: MODAL_BTN.secondary },
                {
                    label: 'SAVE SETTINGS',
                    icon: modalButtonIcon('check'),
                    className: MODAL_BTN.primary,
                    onClick: function (modalClose) {
                        if (submitting) {
                            return;
                        }
                        submitting = true;

                        postJson(config.apiBase + '/api/update_gemini_keys.php', {
                            csrf_token: config.csrfToken,
                            username: verifiedUsername,
                            password: verifiedPassword,
                            gemini_api_key: document.getElementById('modal-ai-key-1').value,
                            gemini_api_key_2: document.getElementById('modal-ai-key-2').value,
                            gemini_api_key_3: document.getElementById('modal-ai-key-3').value,
                            gemini_api_key_4: document.getElementById('modal-ai-key-4').value,
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
                                showToast('error', 'THE SETTINGS COULD NOT BE SAVED. PLEASE TRY AGAIN.');
                            });
                    },
                },
            ],
        });
    }

    var backupBtn = document.getElementById('create-db-backup-btn');
    if (backupBtn) {
        backupBtn.addEventListener('click', openBackupModal);
    }

    var aiSettingsBtn = document.getElementById('configure-ai-settings-btn');
    if (aiSettingsBtn) {
        aiSettingsBtn.addEventListener('click', openAiSettingsCredentialModal);
    }

    // ---- Bulk update of HGE applications: upload -> preview (nothing saved) -> confirm.
    var bulkFileInput = document.getElementById('bulk-hge-file');
    var bulkChooseBtn = document.getElementById('bulk-hge-choose-btn');
    var bulkFileName = document.getElementById('bulk-hge-file-name');
    var bulkPreviewBtn = document.getElementById('bulk-hge-preview-btn');
    var bulkResult = document.getElementById('bulk-hge-result');
    var BULK_MAX_BYTES = 10 * 1024 * 1024;

    function formatCount(value) {
        return Number(value).toLocaleString('en-US');
    }

    function bulkReset() {
        bulkFileInput.value = '';
        bulkFileName.textContent = 'NO FILE CHOSEN';
        bulkPreviewBtn.disabled = true;
        bulkResult.classList.add('hidden');
        bulkResult.innerHTML = '';
    }

    function bulkTile(count, label, hint, toneClass) {
        return '<div class="rounded-xl border border-gray-200 dark:border-white/10 p-4">' +
            '<p class="text-2xl font-bold tabular-nums ' + toneClass + '">' + formatCount(count) + '</p>' +
            '<p class="mt-1 text-xs font-semibold text-gray-700 dark:text-gray-200">' + label + '</p>' +
            '<p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">' + hint + '</p>' +
            '</div>';
    }

    var BULK_TH = 'px-3 py-2 text-left text-xs font-semibold text-gray-600 dark:text-gray-300';
    var BULK_TD = 'px-3 py-2 align-top text-gray-700 dark:text-gray-300';

    // Rows that could not be read can only be fixed in the file itself, so they
    // are listed in the preview (uncertain matches become the persistent
    // NEEDS REVIEW list below once the preview is confirmed).
    function renderBulkInvalidList(data) {
        var invalid = data.invalid || [];
        var html = '';

        if (invalid.length > 0) {
            html += '<div class="mt-6">' +
                '<h3 class="text-sm font-bold text-gray-800 dark:text-gray-100">UNREADABLE ROWS (' + formatCount(data.counts.invalid) + ')</h3>' +
                '<p class="mt-1 text-xs text-gray-600 dark:text-gray-400">THESE ROWS WERE SKIPPED. FIX THEM IN YOUR FILE AND UPLOAD AGAIN.</p>' +
                '<div class="mt-2 max-h-72 overflow-auto rounded-xl border border-gray-200 dark:border-white/10">' +
                '<table class="min-w-full text-xs"><thead class="sticky top-0 bg-gray-50 dark:bg-surface-800"><tr>' +
                '<th class="' + BULK_TH + '">ROW</th><th class="' + BULK_TH + '">NAME IN YOUR FILE</th><th class="' + BULK_TH + '">PROBLEM</th></tr></thead>' +
                '<tbody class="divide-y divide-gray-100 dark:divide-white/5">';
            invalid.forEach(function (item) {
                html += '<tr><td class="' + BULK_TD + '">' + escapeHtml(item.row) + '</td>' +
                    '<td class="' + BULK_TD + '">' + escapeHtml(item.name) + '</td>' +
                    '<td class="' + BULK_TD + '">' + escapeHtml(item.message) + '</td></tr>';
            });
            html += '</tbody></table></div>';
            if (data.counts.invalid > invalid.length) {
                html += '<p class="mt-1 text-xs text-gray-500 dark:text-gray-400">SHOWING THE FIRST ' + formatCount(invalid.length) + ' OF ' + formatCount(data.counts.invalid) + '. DOWNLOAD THE REVIEW FILE FOR THE FULL LIST.</p>';
            }
            html += '</div>';
        }

        return html;
    }

    function renderBulkPreview(data) {
        var counts = data.counts;
        var needsAttention = counts.review + counts.invalid;
        var html =
            '<p class="text-sm text-gray-700 dark:text-gray-300 mb-4">PREVIEW OF <strong>' + escapeHtml(data.filename || 'THE FILE') + '</strong>: ' +
            formatCount(data.rows_read) + ' ROW(S) READ, ' + formatCount(counts.people) + ' UNIQUE APPLICANT(S)' +
            (data.duplicate_rows > 0 ? ' (' + formatCount(data.duplicate_rows) + ' REPEATED ROW(S) MERGED, EARLIEST DATE KEPT)' : '') +
            '. <strong>NOTHING HAS BEEN SAVED YET.</strong></p>' +
            '<div class="grid grid-cols-2 lg:grid-cols-5 gap-3">' +
            bulkTile(counts.to_file, 'WILL BE FILED', 'MATCHED A LATIN HONOR RECORD', 'text-green-600 dark:text-green-400') +
            bulkTile(counts.already_filed, 'ALREADY FILED', 'SKIPPED, LEFT UNCHANGED', 'text-gray-600 dark:text-gray-300') +
            bulkTile(counts.review, 'NEEDS REVIEW', 'NOT FILED. KEPT FOR YOU TO ACT ON', 'text-yellow-600 dark:text-yellow-400') +
            bulkTile(counts.not_found, 'NOT FOUND', 'NO LATIN HONOR RECORD', 'text-gray-600 dark:text-gray-300') +
            bulkTile(counts.invalid, 'UNREADABLE ROWS', 'MISSING NAME OR BAD DATE', 'text-csc-red-600 dark:text-csc-red-400') +
            '</div>' +
            '<div class="mt-5 flex flex-wrap items-center gap-3">';

        if (counts.to_file > 0 || counts.review > 0) {
            var confirmLabel = counts.to_file > 0
                ? 'CONFIRM &amp; FILE ' + formatCount(counts.to_file) + ' APPLICATION(S)'
                : 'CONFIRM &amp; SAVE ' + formatCount(counts.review) + ' FOR REVIEW';
            html += '<button type="button" id="bulk-hge-confirm-btn" class="inline-flex items-center gap-1.5 rounded-xl bg-csc-navy-600 hover:bg-csc-navy-500 text-white text-sm font-semibold px-4 py-2.5 transition-colors">' +
                modalButtonIcon('check') + '<span>' + confirmLabel + '</span></button>';
        }
        if (counts.review > 0) {
            html += '<button type="button" id="bulk-hge-review-now-btn" class="inline-flex items-center gap-1.5 rounded-xl border border-yellow-500/50 text-yellow-700 dark:text-yellow-400 hover:bg-yellow-500/10 text-sm font-semibold px-4 py-2.5 transition-colors">' +
                '<svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 13.5L12 21m0 0l-7.5-7.5M12 21V3" /></svg>' +
                '<span>REVIEW ' + formatCount(counts.review) + ' NOW</span></button>';
        }
        if (needsAttention > 0) {
            html += '<a href="' + config.apiBase + '/api/bulk_hge_review_report.php?token=' + encodeURIComponent(data.token) + '" class="inline-flex items-center gap-1.5 rounded-xl border border-gray-300 dark:border-white/15 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 text-sm font-medium px-4 py-2.5 transition-colors">' +
                modalButtonIcon('download') + '<span>DOWNLOAD REVIEW FILE (' + formatCount(needsAttention) + ')</span></a>';
        }
        html += '<button type="button" id="bulk-hge-cancel-btn" class="inline-flex items-center gap-1.5 rounded-xl border border-gray-300 dark:border-white/15 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 text-sm font-medium px-4 py-2.5 transition-colors">START OVER</button>' +
            '</div>';

        if (counts.to_file === 0 && counts.review === 0) {
            html += '<p class="mt-4 text-sm font-semibold text-gray-700 dark:text-gray-300">NOTHING NEW TO FILE OR REVIEW FROM THIS FILE.</p>';
        } else if (counts.to_file === 0) {
            html += '<p class="mt-4 text-sm text-gray-500 dark:text-gray-400">NO NEW APPLICATIONS CAN BE FILED AUTOMATICALLY FROM THIS FILE.</p>';
        }
        if (counts.handled > 0) {
            html += '<p class="mt-4 text-sm text-gray-600 dark:text-gray-400"><strong>' + formatCount(counts.handled) + '</strong> UNCERTAIN APPLICANT(S) IN THIS FILE WERE ALREADY HANDLED ' +
                '(FILED OR DISMISSED FROM THE NEEDS REVIEW LIST EARLIER), SO THEY ARE NOT RAISED AGAIN.</p>';
        }
        if (counts.review > 0) {
            html += '<p class="mt-4 text-sm text-gray-600 dark:text-gray-400">WHEN YOU CONFIRM, THE <strong>' + formatCount(counts.review) + '</strong> UNCERTAIN APPLICANT(S) ARE SAVED IN THE ' +
                '<strong>NEEDS REVIEW</strong> LIST BELOW, WHERE YOU CAN FILE EACH ONE AGAINST THE RIGHT RECORD OR DISMISS IT. THEY STAY THERE UNTIL YOU DO.</p>';
        }

        html += renderBulkInvalidList(data);

        bulkResult.innerHTML = html;
        bulkResult.classList.remove('hidden');

        document.getElementById('bulk-hge-cancel-btn').addEventListener('click', bulkReset);
        var confirmBtn = document.getElementById('bulk-hge-confirm-btn');
        if (confirmBtn) {
            confirmBtn.addEventListener('click', function () {
                confirmBulkApply(data);
            });
        }
        // The uncertain applicants are only saved (and so only listed below) once the preview is confirmed.
        var reviewNowBtn = document.getElementById('bulk-hge-review-now-btn');
        if (reviewNowBtn) {
            reviewNowBtn.addEventListener('click', function () {
                confirmBulkApply(data);
            });
        }
    }

    function confirmBulkApply(data) {
        var submitting = false;

        var filing = data.counts.to_file > 0;

        showModal({
            icon: modalHeaderIcon('check'),
            title: filing ? 'FILE ' + formatCount(data.counts.to_file) + ' APPLICATION(S)?' : 'SAVE ' + formatCount(data.counts.review) + ' FOR REVIEW?',
            subtitle: filing ? 'Each matched graduate is marked as applied using the date in your file.' : 'Nothing is filed. You decide each one in the NEEDS REVIEW list.',
            bodyHtml: '<p>' + (filing ? formatCount(data.counts.to_file) + ' APPLICATION(S) WILL BE RECORDED. GRADUATES THAT ARE ALREADY FILED ARE NOT CHANGED.' : 'NO APPLICATIONS WILL BE FILED FROM THIS FILE.') +
                (data.counts.review > 0 ? ' THE ' + formatCount(data.counts.review) + ' UNCERTAIN APPLICANT(S) ARE NOT FILED; THEY ARE SAVED IN THE NEEDS REVIEW LIST FOR YOU TO ACT ON.' : '') +
                (data.counts.invalid > 0 ? ' THE ' + formatCount(data.counts.invalid) + ' UNREADABLE ROW(S) ARE SKIPPED.' : '') + '</p>',
            buttons: [
                { label: 'CANCEL', className: MODAL_BTN.secondary },
                {
                    label: filing ? 'CONFIRM & FILE' : 'SAVE & REVIEW',
                    icon: modalButtonIcon('check'),
                    className: MODAL_BTN.primary,
                    onClick: function (modalClose) {
                        if (submitting) {
                            return;
                        }
                        submitting = true;
                        modalClose();

                        var closeLoading = showLoadingModal('FILING APPLICATIONS', 'Please wait while the applications are recorded.');
                        postJson(config.apiBase + '/api/bulk_hge_apply.php', {
                            csrf_token: config.csrfToken,
                            token: data.token,
                        })
                            .then(function (result) {
                                closeLoading();
                                if (result.status === 'success') {
                                    showToast('success', result.message);
                                    bulkReset();
                                    bulkResult.innerHTML = '<p class="text-sm text-gray-700 dark:text-gray-300"><strong>' + formatCount(result.filed) + '</strong> APPLICATION(S) FILED' +
                                        (result.skipped > 0 ? ', ' + formatCount(result.skipped) + ' SKIPPED BECAUSE THEY WERE ALREADY FILED' : '') + '.' +
                                        (result.review_added + result.review_updated > 0 ? ' <strong>' + formatCount(result.review_added + result.review_updated) + '</strong> APPLICANT(S) WERE SAVED IN THE NEEDS REVIEW LIST BELOW.' : '') + '</p>';
                                    bulkResult.classList.remove('hidden');
                                    var savedForReview = result.review_added + result.review_updated > 0;
                                    loadBulkReview().then(function () {
                                        if (savedForReview) {
                                            scrollToBulkReview();
                                        }
                                    });
                                } else {
                                    showToast('error', result.message);
                                    bulkReset();
                                }
                            })
                            .catch(function () {
                                closeLoading();
                                showToast('error', 'THE APPLICATIONS COULD NOT BE FILED. PLEASE TRY AGAIN.');
                            });
                    },
                },
            ],
        });
    }

    // ---- NEEDS REVIEW: applicants from a bulk update whose match was uncertain.
    // They are saved on the server when a preview is confirmed and stay listed
    // here, across page loads and sessions, until an admin files or dismisses each.
    var bulkReviewBox = document.getElementById('bulk-hge-review');
    var bulkReviewItems = {};
    var BULK_REVIEW_SMALL_BTN = 'inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold transition-colors ';
    var BULK_REVIEW_FILE_CLASS = BULK_REVIEW_SMALL_BTN + 'border-csc-navy-600 bg-csc-navy-600 text-white hover:bg-csc-navy-500';
    var BULK_REVIEW_DISMISS_CLASS = BULK_REVIEW_SMALL_BTN + 'border-gray-300 dark:border-white/15 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5';

    function bulkReviewCandidateHtml(item, candidate) {
        var action = candidate.applied_on
            ? '<span class="text-xs font-semibold text-gray-500 dark:text-gray-400">ALREADY FILED (' + escapeHtml(candidate.applied_on) + ')</span>'
            : '<button type="button" data-bulk-review-file data-item-id="' + item.id + '" data-graduate-id="' + candidate.id + '" class="' + BULK_REVIEW_FILE_CLASS + '">FILE FOR THIS RECORD</button>';

        return '<div class="flex flex-wrap items-center justify-between gap-2 py-2">' +
            '<div class="text-sm text-gray-800 dark:text-gray-200"><span class="font-semibold">' + escapeHtml(candidate.name) + '</span>' +
            '<span class="block text-xs text-gray-500 dark:text-gray-400">' + escapeHtml(candidate.school) + ' · ' + escapeHtml(candidate.year) +
            (candidate.course ? ' · ' + escapeHtml(candidate.course) : '') + ' · ' + escapeHtml(candidate.honor) + '</span></div>' +
            action + '</div>';
    }

    // "GO TO NEEDS REVIEW (n)" in the card's top row: shown while items are
    // waiting, and scrolls to the list below (which can be far down the page
    // under a long preview).
    var bulkGotoBtn = document.getElementById('bulk-hge-goto-review-btn');
    var bulkGotoLabel = document.getElementById('bulk-hge-goto-review-label');

    function scrollToBulkReview() {
        if (!bulkReviewBox || bulkReviewBox.classList.contains('hidden')) {
            return;
        }
        var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        bulkReviewBox.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
        bulkReviewBox.classList.add('ring-2', 'ring-yellow-400/70', 'rounded-xl');
        setTimeout(function () {
            bulkReviewBox.classList.remove('ring-2', 'ring-yellow-400/70', 'rounded-xl');
        }, 1800);
    }

    if (bulkGotoBtn) {
        bulkGotoBtn.addEventListener('click', scrollToBulkReview);
    }

    function renderBulkReview(data) {
        bulkReviewItems = {};
        if (bulkGotoBtn) {
            bulkGotoBtn.classList.toggle('hidden', !data.total);
            bulkGotoLabel.textContent = 'GO TO NEEDS REVIEW (' + formatCount(data.total || 0) + ')';
        }
        if (!data.total) {
            bulkReviewBox.classList.add('hidden');
            bulkReviewBox.innerHTML = '';
            return;
        }

        var html = '<h3 class="text-sm font-bold text-gray-800 dark:text-gray-100">NEEDS REVIEW (' + formatCount(data.total) + ')</h3>' +
            '<p class="mt-1 mb-3 text-xs text-gray-600 dark:text-gray-400">THESE APPLICANTS FROM A BULK UPDATE WERE <strong>NOT</strong> FILED BECAUSE THE MATCH IS NOT CERTAIN. ' +
            'FOR EACH ONE, FILE THE APPLICATION AGAINST THE RIGHT SAVED RECORD (THE DATE FROM YOUR FILE IS USED), OR DISMISS IT IF NONE OF THEM IS THE SAME PERSON. ' +
            'THEY STAY HERE UNTIL YOU DO.</p><div class="space-y-3">';

        data.items.forEach(function (item) {
            bulkReviewItems[item.id] = item;
            var candidates = item.candidates.length > 0
                ? item.candidates.map(function (c) { return bulkReviewCandidateHtml(item, c); }).join('')
                : '<p class="py-2 text-xs text-gray-500 dark:text-gray-400">THE SAVED RECORDS THAT MATCHED THIS APPLICANT NO LONGER EXIST. DISMISS THIS ITEM.</p>';

            html += '<div class="rounded-xl border border-yellow-200 dark:border-yellow-500/30 bg-yellow-50/40 dark:bg-yellow-500/5 p-4">' +
                '<div class="flex flex-wrap items-start justify-between gap-3">' +
                '<div><p class="text-sm font-bold text-gray-900 dark:text-white">' + escapeHtml(item.name) + '</p>' +
                '<p class="mt-0.5 text-xs text-gray-600 dark:text-gray-400">DATE APPLIED: <strong>' + escapeHtml(item.date) + '</strong> · FROM ' +
                escapeHtml(item.source_filename || 'A BULK FILE') + (item.file_row ? ', ROW ' + escapeHtml(item.file_row) : '') + '</p>' +
                '<p class="mt-0.5 text-xs font-semibold text-yellow-700 dark:text-yellow-400">' + escapeHtml(item.reason_label) +
                '<span class="font-normal text-gray-600 dark:text-gray-400"> — ' + escapeHtml(item.reason_hint) + '</span></p></div>' +
                '<button type="button" data-bulk-review-dismiss data-item-id="' + item.id + '" class="' + BULK_REVIEW_DISMISS_CLASS + '">NOT A MATCH · DISMISS</button></div>' +
                '<div class="mt-2 divide-y divide-yellow-100 dark:divide-white/5">' + candidates + '</div></div>';
        });
        html += '</div>';

        if (data.total > data.items.length) {
            html += '<p class="mt-3 text-xs text-gray-500 dark:text-gray-400">SHOWING THE FIRST ' + formatCount(data.items.length) +
                ' OF ' + formatCount(data.total) + '. THE REST APPEAR AS YOU RESOLVE THESE.</p>';
        }

        bulkReviewBox.innerHTML = html;
        bulkReviewBox.classList.remove('hidden');
    }

    function loadBulkReview() {
        if (!bulkReviewBox) {
            return Promise.resolve();
        }
        return fetch(config.apiBase + '/api/bulk_hge_review_list.php')
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (data.status === 'success') {
                    renderBulkReview(data);
                } else {
                    bulkReviewBox.innerHTML = '<p class="text-sm font-semibold text-csc-red-600 dark:text-csc-red-400">' + escapeHtml(data.message) + '</p>';
                    bulkReviewBox.classList.remove('hidden');
                }
            })
            .catch(function () {
                bulkReviewBox.innerHTML = '<p class="text-sm font-semibold text-csc-red-600 dark:text-csc-red-400">THE NEEDS REVIEW LIST COULD NOT BE LOADED. PLEASE REFRESH THE PAGE.</p>';
                bulkReviewBox.classList.remove('hidden');
            });
    }

    function resolveBulkReview(payload, loadingTitle) {
        var closeLoading = showLoadingModal(loadingTitle, 'Please wait.');
        payload.csrf_token = config.csrfToken;
        postJson(config.apiBase + '/api/bulk_hge_review_resolve.php', payload)
            .then(function (result) {
                closeLoading();
                showToast(result.status === 'success' ? 'success' : 'error', result.message);
                loadBulkReview();
            })
            .catch(function () {
                closeLoading();
                showToast('error', 'THE ITEM COULD NOT BE UPDATED. PLEASE TRY AGAIN.');
            });
    }

    function confirmBulkReviewFile(item, candidate) {
        showModal({
            icon: modalHeaderIcon('check'),
            title: 'FILE THIS APPLICATION?',
            subtitle: 'The application is recorded against the saved record you chose.',
            bodyHtml: '<p>APPLICANT IN YOUR FILE: <strong>' + escapeHtml(item.name) + '</strong><br>' +
                'WILL BE FILED FOR: <strong>' + escapeHtml(candidate.name) + '</strong> (' + escapeHtml(candidate.school) + ', ' + escapeHtml(candidate.year) + ')<br>' +
                'DATE APPLIED: <strong>' + escapeHtml(item.date) + '</strong></p>',
            buttons: [
                { label: 'CANCEL', className: MODAL_BTN.secondary },
                {
                    label: 'FILE APPLICATION',
                    icon: modalButtonIcon('check'),
                    className: MODAL_BTN.primary,
                    onClick: function (modalClose) {
                        modalClose();
                        resolveBulkReview({ item_id: item.id, action: 'file', graduate_id: candidate.id }, 'FILING APPLICATION');
                    },
                },
            ],
        });
    }

    function confirmBulkReviewDismiss(item) {
        showModal({
            icon: modalHeaderIcon('check'),
            title: 'DISMISS THIS ITEM?',
            subtitle: 'Nothing is filed for this applicant.',
            bodyHtml: '<p>NONE OF THE SAVED RECORDS WILL BE FILED FOR <strong>' + escapeHtml(item.name) + '</strong>, AND THE ITEM IS REMOVED FROM THE LIST. ' +
                'IT IS NOT RAISED AGAIN IF THE SAME PERSON APPEARS IN A LATER FILE.</p>',
            buttons: [
                { label: 'CANCEL', className: MODAL_BTN.secondary },
                {
                    label: 'DISMISS',
                    className: MODAL_BTN.danger,
                    onClick: function (modalClose) {
                        modalClose();
                        resolveBulkReview({ item_id: item.id, action: 'dismiss' }, 'DISMISSING ITEM');
                    },
                },
            ],
        });
    }

    if (bulkReviewBox) {
        bulkReviewBox.addEventListener('click', function (event) {
            var fileBtn = event.target.closest('[data-bulk-review-file]');
            var dismissBtn = event.target.closest('[data-bulk-review-dismiss]');

            if (fileBtn) {
                var item = bulkReviewItems[fileBtn.getAttribute('data-item-id')];
                var graduateId = parseInt(fileBtn.getAttribute('data-graduate-id'), 10);
                var candidate = item ? item.candidates.filter(function (c) { return c.id === graduateId; })[0] : null;
                if (item && candidate) {
                    confirmBulkReviewFile(item, candidate);
                }
            } else if (dismissBtn) {
                var dismissItem = bulkReviewItems[dismissBtn.getAttribute('data-item-id')];
                if (dismissItem) {
                    confirmBulkReviewDismiss(dismissItem);
                }
            }
        });
        loadBulkReview();
    }

    if (bulkFileInput && bulkChooseBtn && bulkPreviewBtn) {
        bulkChooseBtn.addEventListener('click', function () {
            bulkFileInput.click();
        });

        bulkFileInput.addEventListener('change', function () {
            var file = bulkFileInput.files[0];
            bulkResult.classList.add('hidden');
            bulkResult.innerHTML = '';

            if (!file) {
                bulkFileName.textContent = 'NO FILE CHOSEN';
                bulkPreviewBtn.disabled = true;
                return;
            }
            if (!/\.(xls|xlsx|csv)$/i.test(file.name)) {
                showToast('error', 'ONLY .XLS, .XLSX OR .CSV FILES ARE ACCEPTED.');
                bulkReset();
                return;
            }
            if (file.size > BULK_MAX_BYTES) {
                showToast('error', 'THE FILE EXCEEDS THE 10 MB LIMIT.');
                bulkReset();
                return;
            }
            bulkFileName.textContent = file.name;
            bulkPreviewBtn.disabled = false;
        });

        bulkPreviewBtn.addEventListener('click', function () {
            var file = bulkFileInput.files[0];
            if (!file) {
                return;
            }

            var formData = new FormData();
            formData.set('csrf_token', config.csrfToken);
            formData.set('file', file);

            bulkPreviewBtn.disabled = true;
            var closeLoading = showLoadingModal('READING FILE', 'Matching the applicants against the saved Latin Honor records. Nothing is saved at this step.');

            fetch(config.apiBase + '/api/bulk_hge_preview.php', { method: 'POST', body: formData })
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    closeLoading();
                    bulkPreviewBtn.disabled = false;
                    if (data.status === 'success') {
                        renderBulkPreview(data);
                    } else {
                        showToast('error', data.message);
                    }
                })
                .catch(function () {
                    closeLoading();
                    bulkPreviewBtn.disabled = false;
                    showToast('error', 'THE FILE COULD NOT BE PROCESSED. PLEASE TRY AGAIN.');
                });
        });
    }

});
