/**
 * Client-side DataTables for rendered lists.
 * Sort arrows on every orderable column, a filter in each header,
 * paging, and CSV / Excel / PDF / print of the filtered rows.
 */
(function () {
    'use strict';

    var typesReady = false;

    function textFromData(data) {
        if (data === null || data === undefined) {
            return '';
        }
        var raw = String(data);
        if (raw.indexOf('<') === -1) {
            return raw.trim();
        }
        var holder = document.createElement('div');
        holder.innerHTML = raw;
        return holder.textContent === null ? '' : holder.textContent.trim();
    }

    function numberFromData(data) {
        var text = textFromData(data).replace(/,/g, '').trim();
        var negative = text.charAt(0) === '-' || text.charAt(0) === '(';
        var match = text.match(/\d+(?:\.\d+)?/);
        if (match === null) {
            return 0;
        }
        var value = Number(match[0]);
        if (!Number.isFinite(value)) {
            return 0;
        }
        return negative ? -value : value;
    }

    function registerTypes(DataTableApi) {
        if (typesReady) {
            return;
        }
        DataTableApi.type('list-text', {
            search: textFromData,
            order: {
                pre: textFromData,
                asc: function (left, right) {
                    return String(left).localeCompare(String(right), undefined, { numeric: true, sensitivity: 'base' });
                },
                desc: function (left, right) {
                    return String(right).localeCompare(String(left), undefined, { numeric: true, sensitivity: 'base' });
                },
            },
        });
        DataTableApi.type('list-num', {
            order: {
                pre: numberFromData,
                asc: function (left, right) {
                    return left - right;
                },
                desc: function (left, right) {
                    return right - left;
                },
            },
        });
        typesReady = true;
    }

    function readOrder(tableEl) {
        var raw = tableEl.getAttribute('data-dt-order');
        if (raw === null || raw === '') {
            return [];
        }
        try {
            var parsed = JSON.parse(raw);
            return Array.isArray(parsed) ? parsed : [];
        } catch (error) {
            return [];
        }
    }

    function exportName(tableEl) {
        var prefix = tableEl.getAttribute('data-dt-export') || 'list';
        var now = new Date();
        var pad = function (value) {
            return String(value).padStart(2, '0');
        };
        return prefix + '-' + now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate());
    }

    function scrollHeight(tableEl) {
        var wrap = tableEl.closest('.list-dt-wrap');
        if (wrap === null) {
            return '400px';
        }
        var height = window.innerHeight - wrap.getBoundingClientRect().top - 130;
        return Math.max(220, Math.floor(height)) + 'px';
    }

    function debounce(fn, wait) {
        var timer = 0;
        return function () {
            var args = arguments;
            var host = this;
            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                fn.apply(host, args);
            }, wait);
        };
    }

    function columnDefs(tableEl) {
        var defs = [];
        var headers = tableEl.querySelectorAll('thead th');
        headers.forEach(function (header, index) {
            if (header.getAttribute('data-dt-order') === 'disable' || header.classList.contains('list-dt-actions')) {
                defs.push({
                    targets: index,
                    orderable: false,
                    searchable: false,
                    className: 'list-dt-actions',
                });
                return;
            }
            var def = { targets: index, type: 'list-text' };
            if (header.getAttribute('data-dt-sort') === 'num') {
                def.type = 'list-num';
            }
            defs.push(def);
        });
        return defs;
    }

    function exportable(idx, node) {
        return !(node && node.classList && node.classList.contains('list-dt-actions'));
    }

    function idTokenSearch(term) {
        var needle = term.trim();
        var digits = /^\d+$/.test(needle);
        var lower = needle.toLowerCase();
        return function (data) {
            var text = textFromData(data);
            if (digits) {
                var id = text.match(/^\((\d+)\)/);
                return id !== null && id[1] === needle;
            }
            return text.toLowerCase().indexOf(lower) !== -1;
        };
    }

    function bindFilters(api) {
        api.columns().every(function () {
            var column = this;
            var header = column.header();
            if (header === null) {
                return;
            }
            var input = header.querySelector('input.list-dt-filter');
            if (input === null) {
                return;
            }
            var token = header.getAttribute('data-dt-token') === 'id';
            var apply = debounce(function () {
                var value = input.value;
                if (token && value.trim() !== '') {
                    column.search(idTokenSearch(value)).draw();
                    return;
                }
                column.search(value).draw();
            }, 300);
            input.addEventListener('keyup', apply);
            input.addEventListener('search', apply);
            input.addEventListener('click', function (event) {
                event.stopPropagation();
            });
            input.addEventListener('mousedown', function (event) {
                event.stopPropagation();
            });
            input.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                }
            });
        });
    }

    function holdSelectAll(tableEl) {
        tableEl.querySelectorAll('.list-dt-select-all').forEach(function (input) {
            if (input.getAttribute('data-select-bound') === '1') {
                return;
            }
            input.setAttribute('data-select-bound', '1');
            input.addEventListener('click', function (event) {
                event.stopPropagation();
            });
            input.addEventListener('mousedown', function (event) {
                event.stopPropagation();
            });
        });
    }

    function openInventorySelection(tableEl) {
        if (tableEl.getAttribute('data-dt-page-scroll') !== '1') {
            return null;
        }
        var factory = globalThis.InventorySelectionStore;
        if (!factory || typeof factory.createInventorySelectionStore !== 'function' || typeof factory.bindSelectionChrome !== 'function') {
            return null;
        }
        var form = document.getElementById('stickers');
        if (!(form instanceof HTMLFormElement)) {
            return null;
        }
        var keyInput = form.querySelector('input[name="selection_storage_key"]');
        var storageKey = keyInput instanceof HTMLInputElement ? keyInput.value : '';
        if (storageKey === '') {
            return null;
        }
        var selection = factory.createInventorySelectionStore({
            storage: window.sessionStorage,
            storageKey: storageKey,
        });
        selection.load();
        var chrome = factory.bindSelectionChrome(form, selection, {
            restore: function () {
                factory.restoreChecks(tableEl, selection);
            },
            syncSelectAll: function () {
                factory.syncSelectAll(tableEl);
            },
            uncheckVisible: function () {
                factory.uncheckVisible(tableEl);
            },
        });
        tableEl.addEventListener('change', function (event) {
            onInventorySelectionChange(tableEl, selection, chrome, event.target);
        });
        return {
            draw: function () {
                holdSelectAll(tableEl);
                chrome.refresh();
            },
        };
    }

    function onInventorySelectionChange(tableEl, selection, chrome, target) {
        if (!(target instanceof HTMLInputElement)) {
            return;
        }
        var factory = globalThis.InventorySelectionStore;
        if (target.classList.contains('list-dt-select-all')) {
            var checked = target.checked;
            selection.applyPage(factory.selectableIds(tableEl), checked);
            tableEl.querySelectorAll('tbody input[type="checkbox"][name="item[]"]').forEach(function (box) {
                if (box instanceof HTMLInputElement) {
                    box.checked = checked;
                }
            });
            target.indeterminate = false;
            selection.save();
            factory.syncSelectAll(tableEl);
            chrome.syncBar();
            return;
        }
        if (target.name !== 'item[]' || target.type !== 'checkbox') {
            return;
        }
        selection.setChecked(target.value, target.checked);
        selection.save();
        factory.syncSelectAll(tableEl);
        chrome.syncBar();
    }

    function initTable(tableEl) {
        if (tableEl.id === '' || tableEl.getAttribute('data-dt-ready') === '1') {
            return null;
        }
        if (typeof DataTable === 'undefined') {
            return null;
        }
        registerTypes(DataTable);
        tableEl.setAttribute('data-dt-ready', '1');
        var pageScroll = tableEl.getAttribute('data-dt-page-scroll') === '1';
        if (pageScroll) {
            var scrollWrap = tableEl.closest('.list-dt-wrap');
            if (scrollWrap !== null) {
                scrollWrap.classList.add('list-dt-page-scroll');
            }
        }
        var selectionUi = openInventorySelection(tableEl);
        var name = exportName(tableEl);
        var exportOptions = {
            columns: exportable,
            modifier: { search: 'applied', order: 'applied', page: 'all' },
            format: {
                body: function (data, row, column, node) {
                    if (node && typeof node.textContent === 'string') {
                        return node.textContent.replace(/\s+/g, ' ').trim();
                    }
                    return data === null || data === undefined ? '' : String(data);
                },
            },
        };
        var options = {
            order: readOrder(tableEl),
            pageLength: 10,
            lengthMenu: [10, 25, 50, 100],
            stripe: true,
            searchDelay: 300,
            layout: {
                topStart: ['pageLength', 'buttons'],
                topEnd: 'search',
                bottomStart: 'info',
                bottomEnd: 'paging',
            },
            buttons: [
                { extend: 'csv', filename: name, exportOptions: exportOptions },
                { extend: 'excel', filename: name, exportOptions: exportOptions },
                { extend: 'pdf', filename: name, orientation: 'landscape', pageSize: 'LETTER', exportOptions: exportOptions },
                { extend: 'print', exportOptions: exportOptions },
            ],
            columnDefs: columnDefs(tableEl),
            initComplete: function () {
                bindFilters(this.api());
                this.api().columns.adjust();
            },
        };
        if (!pageScroll) {
            options.scrollY = scrollHeight(tableEl);
            options.scrollX = true;
            options.scrollCollapse = true;
        }
        if (selectionUi !== null) {
            options.drawCallback = function () {
                selectionUi.draw();
            };
        }
        return new DataTable('#' + tableEl.id, options);
    }

    function init() {
        if (typeof DataTable === 'undefined') {
            return;
        }
        var instances = [];
        document.querySelectorAll('table.list-dt').forEach(function (tableEl) {
            var api = initTable(tableEl);
            if (api !== null) {
                instances.push({ api: api, tableEl: tableEl });
            }
        });
        if (instances.length === 0) {
            return;
        }
        var timer = 0;
        window.addEventListener('resize', function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                instances.forEach(function (entry) {
                    var settings = entry.api.settings()[0];
                    if (!settings) {
                        return;
                    }
                    if (entry.tableEl.getAttribute('data-dt-page-scroll') !== '1' && settings.oScroll) {
                        settings.oScroll.sY = scrollHeight(entry.tableEl);
                    }
                    entry.api.columns.adjust();
                });
            }, 150);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
