/**
 * Excel-style column filters / sort for a table header (Look-Up, School Records, Review).
 *
 *   var cf = HgeColumnFilters.create({
 *       columns: [{ key: 'name', label: 'GRADUATE NAME', type: 'text' | 'date' | 'number' }, ...],
 *       chipsEl: document.getElementById('...'),          // where the active filters are shown as removable chips
 *       formatValue: function (col, value) { ... },       // optional: how a value is shown (default: dates and uppercase text)
 *       fetchValues: function (col, query) { return Promise<{ values: [{value, count}], blank_count, truncated }>; },
 *       onChange: function () { ... },                    // called after OK / CLEAR / SORT / a chip is removed
 *   });
 *
 * The header buttons are `.col-filter-btn[data-col]` (server-rendered by column_filter_header_cell() or built with
 * cf.headerCellsHtml()). cf.filters is { columnKey: [value | null, ...] } (null = BLANKS), cf.sortCol / cf.sortDir
 * the header sort. Needs escapeHtml() and showToast() from utils.js.
 */
(function (global) {
    var MONTHS = ['JANUARY', 'FEBRUARY', 'MARCH', 'APRIL', 'MAY', 'JUNE', 'JULY', 'AUGUST', 'SEPTEMBER', 'OCTOBER', 'NOVEMBER', 'DECEMBER'];
    var FUNNEL_ICON = '<svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4.5h18l-7 8.25V19l-4 1.5v-7.75L3 4.5z" /></svg>';
    var CLOSE_ICON = '<svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>';
    var ACTIVE_BTN_CLASSES = ['text-csc-navy-600', 'bg-csc-navy-600/15', 'dark:text-csc-navy-300'];

    function formatIsoDate(value) {
        var parts = String(value).split(' ')[0].split('-');
        if (parts.length !== 3 || !MONTHS[parseInt(parts[1], 10) - 1]) {
            return String(value);
        }
        return MONTHS[parseInt(parts[1], 10) - 1] + ' ' + parseInt(parts[2], 10) + ', ' + parseInt(parts[0], 10);
    }

    function create(options) {
        var columns = options.columns;
        var api = { columns: columns, filters: {}, sortCol: '', sortDir: 'asc' };
        var panel = null;
        var panelState = null;
        var requestToken = 0;
        var searchTimer = null;

        function byKey(key) {
            for (var i = 0; i < columns.length; i++) {
                if (columns[i].key === key) {
                    return columns[i];
                }
            }
            return null;
        }
        api.byKey = byKey;

        function formatValue(col, value) {
            if (value === null) {
                return '(BLANKS)';
            }
            if (options.formatValue) {
                return options.formatValue(col, value);
            }
            return col.type === 'date' ? formatIsoDate(value) : String(value).toUpperCase();
        }

        function sortTexts(col) {
            if (col.type === 'date') {
                return { asc: 'OLDEST TO NEWEST', desc: 'NEWEST TO OLDEST', ascChip: 'OLDEST FIRST', descChip: 'NEWEST FIRST' };
            }
            if (col.type === 'number') {
                return { asc: 'SMALLEST TO LARGEST', desc: 'LARGEST TO SMALLEST', ascChip: 'SMALLEST FIRST', descChip: 'LARGEST FIRST' };
            }
            return { asc: 'A TO Z', desc: 'Z TO A', ascChip: 'A TO Z', descChip: 'Z TO A' };
        }

        api.parse = function (raw) {
            var result = {};
            try {
                var decoded = JSON.parse(raw || '{}');
                Object.keys(decoded || {}).forEach(function (key) {
                    if (byKey(key) && Array.isArray(decoded[key]) && decoded[key].length > 0) {
                        result[key] = decoded[key].filter(function (v) {
                            return v === null || typeof v === 'string';
                        });
                    }
                });
            } catch (e) {
                result = {};
            }
            return result;
        };

        // Restores the state from URL values (?cf=<json>&sort=<key>&dir=<asc|desc>) and redraws the indicators.
        api.setState = function (cfRaw, sort, dir) {
            api.filters = api.parse(cfRaw);
            api.sortCol = byKey(sort) ? sort : '';
            api.sortDir = dir === 'desc' ? 'desc' : 'asc';
            api.refresh();
        };

        // The three request parameters that carry the state ('' = leave out).
        api.params = function () {
            return {
                cf: Object.keys(api.filters).length > 0 ? JSON.stringify(api.filters) : '',
                sort: api.sortCol,
                dir: api.sortCol ? api.sortDir : '',
            };
        };

        api.isActive = function () {
            return Object.keys(api.filters).length > 0 || api.sortCol !== '';
        };

        api.clear = function () {
            api.filters = {};
            api.sortCol = '';
            api.sortDir = 'asc';
            closePanel();
            api.refresh();
        };

        api.headerCellsHtml = function () {
            return columns.map(function (col) {
                return '<th class="px-4 py-3"><div class="flex items-center gap-1.5"><span>' + col.label + '</span>' +
                    '<button type="button" class="col-filter-btn rounded p-1 text-gray-400 hover:bg-gray-100 dark:hover:bg-white/10 hover:text-csc-navy-600 dark:hover:text-csc-navy-300" data-col="' + col.key + '" data-label="' + col.label + '" aria-label="Filter ' + col.label + '" title="Filter / sort">' + FUNNEL_ICON + '</button></div></th>';
            }).join('');
        };

        // Marks the header buttons of filtered / sorted columns and shows one removable chip per active filter
        // (also the way back out when a filter leaves no rows, because then there may be no header to click).
        api.refresh = function () {
            document.querySelectorAll('.col-filter-btn').forEach(function (btn) {
                var active = !!api.filters[btn.dataset.col] || api.sortCol === btn.dataset.col;
                ACTIVE_BTN_CLASSES.forEach(function (c) {
                    btn.classList.toggle(c, active);
                });
                btn.classList.toggle('text-gray-400', !active);
            });

            var chips = options.chipsEl;
            if (!chips) {
                return;
            }
            var html = '';
            Object.keys(api.filters).forEach(function (key) {
                var col = byKey(key);
                var values = api.filters[key];
                var summary = values.length === 1 ? formatValue(col, values[0]) : values.length + ' SELECTED';
                html += '<span class="inline-flex items-center gap-2 rounded-full bg-csc-navy-600/10 dark:bg-csc-navy-600/15 px-3 py-1.5 text-sm font-medium text-csc-navy-600 dark:text-csc-navy-300">' +
                    '<span>' + escapeHtml(col.label) + ': ' + escapeHtml(summary) + '</span>' +
                    '<button type="button" class="column-chip-clear rounded-full p-0.5 hover:bg-csc-navy-600/20 dark:hover:bg-csc-navy-600/25" data-clear="' + key + '" title="Clear this filter" aria-label="Clear this filter">' + CLOSE_ICON + '</button></span>';
            });
            if (api.sortCol) {
                var sortColumn = byKey(api.sortCol);
                var texts = sortTexts(sortColumn);
                html += '<span class="inline-flex items-center gap-2 rounded-full bg-gray-100 dark:bg-white/5 px-3 py-1.5 text-sm font-medium text-gray-600 dark:text-gray-300">' +
                    '<span>SORTED BY ' + escapeHtml(sortColumn.label) + ' (' + (api.sortDir === 'desc' ? texts.descChip : texts.ascChip) + ')</span>' +
                    '<button type="button" class="column-chip-clear rounded-full p-0.5 hover:bg-gray-200 dark:hover:bg-white/10" data-clear="__sort" title="Clear the sort" aria-label="Clear the sort">' + CLOSE_ICON + '</button></span>';
            }
            chips.innerHTML = html;
            chips.classList.toggle('hidden', html === '');
        };

        if (options.chipsEl) {
            options.chipsEl.addEventListener('click', function (event) {
                var clearBtn = event.target.closest('.column-chip-clear');
                if (!clearBtn) {
                    return;
                }
                if (clearBtn.dataset.clear === '__sort') {
                    api.sortCol = '';
                    api.sortDir = 'asc';
                } else {
                    delete api.filters[clearBtn.dataset.clear];
                }
                api.refresh();
                options.onChange();
            });
        }

        // ---- The filter box opened from a header button.
        function closePanel() {
            if (searchTimer) {
                clearTimeout(searchTimer);
                searchTimer = null;
            }
            if (panel) {
                panel.remove();
            }
            panel = null;
            panelState = null;
        }
        api.closePanel = closePanel;

        function displayedKeys() {
            var keys = panelState.values.map(function (v) {
                return v.value;
            });
            if (panelState.blank > 0 && panelState.q === '') {
                keys.push(null);
            }
            return keys;
        }

        function isChecked(key) {
            return panelState.allSelected || panelState.selected.has(key);
        }

        function renderList() {
            var list = panel.querySelector('.col-panel-list');
            var col = panelState.col;
            var keys = displayedKeys();
            var checkedCount = keys.filter(isChecked).length;
            var rowClass = 'flex items-center gap-2 px-3 py-1.5 cursor-pointer hover:bg-gray-100 dark:hover:bg-white/5';

            var rows = '<label class="' + rowClass + '">' +
                '<input type="checkbox" class="col-panel-all rounded border-gray-300" ' + (keys.length > 0 && checkedCount === keys.length ? 'checked' : '') + '>' +
                '<span class="font-medium">(SELECT ALL' + (panelState.q !== '' ? ' SEARCH RESULTS' : '') + ')</span></label>';

            panelState.values.forEach(function (item, i) {
                var label = formatValue(col, item.value);
                rows += '<label class="' + rowClass + '">' +
                    '<input type="checkbox" class="col-panel-item rounded border-gray-300" data-index="' + i + '" ' + (isChecked(item.value) ? 'checked' : '') + '>' +
                    '<span class="flex-1 truncate" title="' + escapeHtml(label) + '">' + escapeHtml(label) + '</span>' +
                    '<span class="text-xs text-gray-400">' + item.count + '</span></label>';
            });
            if (panelState.blank > 0 && panelState.q === '') {
                rows += '<label class="' + rowClass + '">' +
                    '<input type="checkbox" class="col-panel-item rounded border-gray-300" data-index="blank" ' + (isChecked(null) ? 'checked' : '') + '>' +
                    '<span class="flex-1">(BLANKS)</span><span class="text-xs text-gray-400">' + panelState.blank + '</span></label>';
            }
            if (keys.length === 0) {
                rows += '<p class="px-3 py-3 text-xs text-gray-500 dark:text-gray-400">' + (panelState.loading ? 'LOADING...' : 'NO MATCHING VALUES.') + '</p>';
            }
            list.innerHTML = rows;

            var allBox = list.querySelector('.col-panel-all');
            if (allBox) {
                allBox.indeterminate = checkedCount > 0 && checkedCount < keys.length;
            }

            panel.querySelector('.col-panel-note').classList.toggle('hidden', !(panelState.truncated && panelState.q === ''));
            panel.querySelector('[data-act="ok"]').disabled = !(panelState.allSelected || panelState.selected.size > 0);
        }

        function loadValues() {
            var token = ++requestToken;
            panelState.loading = true;

            Promise.resolve(options.fetchValues(panelState.col, panelState.q))
                .then(function (data) {
                    if (token !== requestToken || !panel) {
                        return;
                    }
                    panelState.loading = false;
                    panelState.values = data.values;
                    panelState.blank = data.blank_count;
                    panelState.truncated = data.truncated;
                    renderList();
                })
                .catch(function (error) {
                    if (token === requestToken && panel) {
                        panelState.loading = false;
                        showToast('error', (error && error.message) || 'THE VALUES COULD NOT BE LOADED. PLEASE TRY AGAIN.');
                        renderList();
                    }
                });
        }

        // Turns "everything ticked" into an explicit set of the listed values, so one of them can then be unticked.
        function materializeSelection() {
            if (!panelState.allSelected) {
                return;
            }
            panelState.allSelected = false;
            panelState.selected = new Set(displayedKeys());
        }

        function applyChange() {
            closePanel();
            api.refresh();
            options.onChange();
        }

        function openPanel(btn) {
            closePanel();
            var col = byKey(btn.dataset.col);
            var existing = api.filters[col.key];
            var texts = sortTexts(col);

            panelState = {
                col: col,
                q: '',
                values: [],
                blank: 0,
                truncated: false,
                loading: true,
                allSelected: !existing,
                selected: new Set(existing || []),
            };

            panel = document.createElement('div');
            panel.id = 'col-filter-panel';
            // z-[5]: above the table rows, but below the sticky filter card (z-10) and the top bar (z-20)
            panel.className = 'absolute z-[5] w-72 rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-surface-900 shadow-xl p-2 text-sm font-normal text-gray-800 dark:text-gray-200';
            var itemClass = 'w-full text-left px-3 py-2 rounded-lg hover:bg-gray-100 dark:hover:bg-white/5 disabled:opacity-40 disabled:pointer-events-none';
            panel.innerHTML =
                '<button type="button" data-act="sort-asc" class="' + itemClass + '">SORT ' + texts.asc + '</button>' +
                '<button type="button" data-act="sort-desc" class="' + itemClass + '">SORT ' + texts.desc + '</button>' +
                '<div class="my-1 border-t border-gray-200 dark:border-white/10"></div>' +
                '<button type="button" data-act="clear" class="' + itemClass + '"' + (existing ? '' : ' disabled') + '>CLEAR FILTER FROM "' + escapeHtml(col.label) + '"</button>' +
                '<div class="my-1 border-t border-gray-200 dark:border-white/10"></div>' +
                '<input type="search" class="col-panel-search w-full rounded-lg bg-gray-50 dark:bg-surface-800 border border-gray-200 dark:border-white/10 px-3 py-2 text-sm placeholder:text-gray-400 focus:border-csc-navy-500 focus:ring-2 focus:ring-csc-navy-500/20 focus:outline-none" placeholder="SEARCH" autocomplete="off">' +
                '<div class="col-panel-list mt-2 max-h-60 overflow-y-auto rounded-lg border border-gray-200 dark:border-white/10"></div>' +
                '<p class="col-panel-note hidden mt-2 px-1 text-xs text-gray-500 dark:text-gray-400">ONLY THE FIRST VALUES ARE LISTED. SEARCH, THEN TICK THE ONES YOU WANT.</p>' +
                '<div class="mt-2 flex justify-end gap-2">' +
                '<button type="button" data-act="cancel" class="rounded-lg border border-gray-300 dark:border-white/15 px-4 py-1.5 text-sm font-medium hover:bg-gray-50 dark:hover:bg-white/5">CANCEL</button>' +
                '<button type="button" data-act="ok" class="rounded-lg bg-csc-navy-600 hover:bg-csc-navy-500 text-white px-4 py-1.5 text-sm font-semibold disabled:opacity-40 disabled:pointer-events-none">OK</button></div>';
            document.body.appendChild(panel);

            var rect = btn.getBoundingClientRect();
            var panelWidth = panel.offsetWidth;
            var left = rect.left + window.scrollX;
            left = Math.max(window.scrollX + 8, Math.min(left, window.scrollX + document.documentElement.clientWidth - panelWidth - 8));
            panel.style.left = left + 'px';
            panel.style.top = (rect.bottom + window.scrollY + 6) + 'px';

            renderList();
            loadValues();
            panel.querySelector('.col-panel-search').focus();

            panel.addEventListener('click', function (event) {
                var actBtn = event.target.closest('[data-act]');
                if (!actBtn) {
                    return;
                }
                var act = actBtn.dataset.act;
                if (act === 'cancel') {
                    closePanel();
                } else if (act === 'sort-asc' || act === 'sort-desc') {
                    api.sortCol = col.key;
                    api.sortDir = act === 'sort-desc' ? 'desc' : 'asc';
                    applyChange();
                } else if (act === 'clear') {
                    delete api.filters[col.key];
                    applyChange();
                } else if (act === 'ok') {
                    if (panelState.allSelected && panelState.q === '') {
                        delete api.filters[col.key];
                    } else {
                        materializeSelection();
                        api.filters[col.key] = Array.from(panelState.selected);
                    }
                    applyChange();
                }
            });

            panel.addEventListener('change', function (event) {
                var target = event.target;
                if (target.classList.contains('col-panel-all')) {
                    if (target.checked) {
                        if (panelState.q === '') {
                            panelState.allSelected = true;
                            panelState.selected = new Set();
                        } else {
                            materializeSelection();
                            displayedKeys().forEach(function (key) {
                                panelState.selected.add(key);
                            });
                        }
                    } else if (panelState.q === '') {
                        panelState.allSelected = false;
                        panelState.selected = new Set();
                    } else {
                        materializeSelection();
                        displayedKeys().forEach(function (key) {
                            panelState.selected.delete(key);
                        });
                    }
                } else if (target.classList.contains('col-panel-item')) {
                    var key = target.dataset.index === 'blank' ? null : panelState.values[parseInt(target.dataset.index, 10)].value;
                    if (panelState.allSelected && panelState.truncated && panelState.q === '') {
                        // too many values to start from "everything": begin a fresh selection with this one
                        panelState.allSelected = false;
                        panelState.selected = new Set([key]);
                    } else {
                        materializeSelection();
                        if (target.checked) {
                            panelState.selected.add(key);
                        } else {
                            panelState.selected.delete(key);
                        }
                    }
                }
                renderList();
            });

            panel.querySelector('.col-panel-search').addEventListener('input', function (event) {
                var value = event.target.value.trim();
                if (searchTimer) {
                    clearTimeout(searchTimer);
                }
                searchTimer = setTimeout(function () {
                    if (panel && panelState.q !== value) {
                        panelState.q = value;
                        loadValues();
                    }
                }, 250);
            });
        }

        document.addEventListener('click', function (event) {
            var filterBtn = event.target.closest('.col-filter-btn');
            if (filterBtn) {
                if (panel && panelState && panelState.col.key === filterBtn.dataset.col) {
                    closePanel();
                } else {
                    openPanel(filterBtn);
                }
                return;
            }
            if (panel && !panel.contains(event.target)) {
                closePanel();
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && panel) {
                closePanel();
            }
        });

        return api;
    }

    global.HgeColumnFilters = { create: create, formatIsoDate: formatIsoDate };
})(window);
