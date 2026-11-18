/**
 * DataTables for rendered lists.
 * Sort arrows, a filter in each header, paging, column visibility,
 * and CSV / Excel / PDF / print of the filtered rows.
 * Tables marked data-dt-server="1" ask the server for one page.
 * Their Excel and PDF libraries load on that button, not with the page.
 */
(function () {
    'use strict';

    var typesReady = false;

    function textFromData(data) {
        if (data === null || data === undefined) {
            return '';
        }
        var raw = String(data);
        if (raw.indexOf('<') === -1 && raw.indexOf('&') === -1) {
            return raw.trim();
        }
        // Decode entities / strip markup without assigning to a live element's innerHTML
        // (avoids re-creating elements from escaped attacker text).
        var template = document.createElement('template');
        template.innerHTML = raw;
        var text = template.content.textContent;
        return text === null ? '' : text.trim();
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
            search: textFromData,
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

    function titleHeaders(tableEl) {
        var row = tableEl.querySelector('thead tr.list-dt-titles');
        if (row !== null) {
            return row.querySelectorAll('th');
        }
        var first = tableEl.querySelector('thead tr');
        if (first === null) {
            return tableEl.querySelectorAll('thead th');
        }
        return first.querySelectorAll('th');
    }

    function columnDefs(tableEl) {
        var defs = [];
        var server = tableEl.getAttribute('data-dt-server') === '1';
        var headers = titleHeaders(tableEl);
        headers.forEach(function (header, index) {
            if (header.getAttribute('data-dt-order') === 'disable' || header.classList.contains('list-dt-actions')) {
                var action = {
                    targets: index,
                    orderable: false,
                    searchable: false,
                    className: 'list-dt-actions',
                };
                if (server) {
                    action.data = String(index);
                }
                defs.push(action);
                return;
            }
            var def = { targets: index, type: 'list-text' };
            if (header.getAttribute('data-dt-sort') === 'num') {
                def.type = 'list-num';
            }
            if (server) {
                def.data = String(index);
                var key = header.getAttribute('data-dt-key');
                if (key) {
                    def.name = key;
                }
                if (header.className) {
                    def.className = header.className;
                }
            }
            defs.push(def);
        });
        return defs;
    }

    function columnTitle(dt, idx, title) {
        var header = dt.column(idx).header();
        if (header !== null) {
            var labeled = header.querySelector('.dt-column-title');
            if (labeled !== null) {
                var text = labeled.textContent === null ? '' : labeled.textContent.trim();
                if (text !== '') {
                    return text;
                }
            }
        }
        var cleaned = String(title || '').replace(/\s+/g, ' ').trim();
        return cleaned === '' ? 'Column ' + (idx + 1) : cleaned;
    }

    function visibilityStorageKey(tableEl) {
        return 'pc-list-dt-columns:' + tableEl.id;
    }

    function readColumnVisibility(tableEl, columnCount) {
        try {
            var raw = window.localStorage.getItem(visibilityStorageKey(tableEl));
            if (raw === null || raw === '') {
                return null;
            }
            var parsed = JSON.parse(raw);
            if (!Array.isArray(parsed) || parsed.length !== columnCount) {
                return null;
            }
            var visible = [];
            for (var i = 0; i < parsed.length; i++) {
                if (typeof parsed[i] !== 'boolean') {
                    return null;
                }
                visible.push(parsed[i]);
            }
            return visible;
        } catch (error) {
            return null;
        }
    }

    function rememberColumnVisibility(tableEl, api) {
        var visible = [];
        api.columns().every(function () {
            visible.push(this.visible());
        });
        try {
            window.localStorage.setItem(visibilityStorageKey(tableEl), JSON.stringify(visible));
        } catch (error) {}
    }

    function applyStoredVisibility(tableEl, api) {
        var headers = titleHeaders(tableEl);
        var stored = readColumnVisibility(tableEl, headers.length);
        if (stored === null) {
            return;
        }
        stored.forEach(function (show, index) {
            var header = headers[index];
            if (header && header.classList.contains('list-dt-actions')) {
                return;
            }
            api.column(index).visible(show, false);
        });
        api.columns.adjust();
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

    function quietClonedFilters(tableEl) {
        var scroll = tableEl.closest('.dt-scroll');
        if (scroll === null) {
            return;
        }
        scroll.querySelectorAll('.dt-scroll-body input.list-dt-filter').forEach(function (input) {
            input.tabIndex = -1;
            input.setAttribute('aria-hidden', 'true');
        });
    }

    function bindFilters(api, tableEl) {
        var scroll = tableEl.closest('.dt-scroll');
        var host = scroll === null ? tableEl : scroll;
        if (host.getAttribute('data-filter-bound') === '1') {
            return;
        }
        host.setAttribute('data-filter-bound', '1');
        var timers = new WeakMap();

        function columnIndex(input) {
            var cell = input.closest('th');
            var row = cell === null ? null : cell.parentElement;
            if (cell === null || row === null) {
                return -1;
            }
            return Array.prototype.indexOf.call(row.children, cell);
        }

        function applyInput(input) {
            var index = columnIndex(input);
            if (index < 0) {
                return;
            }
            var column = api.column(index);
            var thead = input.closest('thead');
            var titles = thead === null ? null : thead.querySelector('tr.list-dt-titles');
            var titleCell = titles === null ? null : titles.children[index];
            var token = titleCell !== null && titleCell.getAttribute('data-dt-token') === 'id';
            var value = input.value;
            if (token && value.trim() !== '') {
                column.search(idTokenSearch(value)).draw();
                return;
            }
            column.search(value, { exact: false, regex: false, smart: false, caseInsensitive: true }).draw();
        }

        function schedule(input) {
            var pending = timers.get(input);
            if (pending) {
                window.clearTimeout(pending);
            }
            timers.set(input, window.setTimeout(function () {
                applyInput(input);
            }, 300));
        }

        function filterInput(event) {
            var input = event.target;
            if (!(input instanceof HTMLInputElement) || !input.classList.contains('list-dt-filter')) {
                return null;
            }
            if (scroll !== null && input.closest('.dt-scroll-head') === null) {
                return null;
            }
            return input;
        }

        host.addEventListener('input', function (event) {
            var input = filterInput(event);
            if (input !== null) {
                schedule(input);
            }
        });
        host.addEventListener('keyup', function (event) {
            var input = filterInput(event);
            if (input !== null) {
                schedule(input);
            }
        });
        host.addEventListener('search', function (event) {
            var input = filterInput(event);
            if (input !== null) {
                schedule(input);
            }
        });
        host.addEventListener('keydown', function (event) {
            var input = filterInput(event);
            if (input !== null && event.key === 'Enter') {
                event.preventDefault();
            }
        });
        host.addEventListener('mousedown', function (event) {
            if (filterInput(event) !== null) {
                event.stopPropagation();
            }
        }, true);
        host.addEventListener('click', function (event) {
            if (filterInput(event) !== null) {
                event.stopPropagation();
            }
        }, true);
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

    var PAGE_LENGTHS = [10, 25, 50, 100];
    var PAGE_LENGTH_KEY = 'pc-page-length';

    function storedPageLength() {
        try {
            var saved = parseInt(window.localStorage.getItem(PAGE_LENGTH_KEY), 10);
            if (PAGE_LENGTHS.indexOf(saved) !== -1) {
                return saved;
            }
        } catch (e) {}
        return 10;
    }

    function rememberPageLength(length) {
        if (PAGE_LENGTHS.indexOf(length) === -1) {
            return;
        }
        try {
            window.localStorage.setItem(PAGE_LENGTH_KEY, String(length));
        } catch (e) {}
    }

    function serverStart(tableEl, length) {
        var index = parseInt(tableEl.getAttribute('data-dt-start') || '0', 10);
        if (!Number.isFinite(index) || index < 1) {
            return 0;
        }
        var size = PAGE_LENGTHS.indexOf(length) === -1 ? 10 : length;
        return Math.floor(index / size) * size;
    }

    function applyServer(options, tableEl, name) {
        options.serverSide = true;
        options.processing = true;
        options.ajax = tableEl.getAttribute('data-dt-rows') || '';
        options.displayStart = serverStart(tableEl, options.pageLength);
        options.buttons = serverButtons(tableEl, name);
        var empty = tableEl.getAttribute('data-dt-empty');
        if (empty) {
            options.language = { emptyTable: empty, zeroRecords: empty };
        }
    }

    function serverButtons(tableEl, name) {
        return [
            {
                extend: 'colvis',
                text: 'Columns',
                columns: ':not(.list-dt-actions)',
                columnText: columnTitle,
                popoverTitle: 'Show or hide columns',
            },
            { text: 'CSV', action: function (e, dt) { exportServer(dt, tableEl, 'csv', name); } },
            { text: 'Excel', action: function (e, dt) { exportServer(dt, tableEl, 'excel', name); } },
            { text: 'PDF', action: function (e, dt) { exportServer(dt, tableEl, 'pdf', name); } },
            { text: 'Print', action: function (e, dt) { exportServer(dt, tableEl, 'print', name); } },
        ];
    }

    function exportServer(api, tableEl, kind, name) {
        var base = tableEl.getAttribute('data-dt-rows') || '';
        var params = api.ajax.params();
        if (!params || typeof params !== 'object') {
            params = {};
        }
        params.export = '1';
        params.start = '0';
        var joiner = base.indexOf('?') === -1 ? '?' : '&';
        var url = base + joiner + queryString(params);
        fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('export failed');
                }
                return response.json();
            })
            .then(function (payload) {
                var picked = visibleColumns(api);
                var rows = [];
                (payload.data || []).forEach(function (row) {
                    rows.push(picked.indexes.map(function (index) {
                        var cell = row[String(index)];
                        if (cell === undefined) {
                            cell = row[index];
                        }
                        return textFromData(cell);
                    }));
                });
                return deliverExport(kind, name, picked.headers, rows);
            })
            .catch(function () {
                window.alert('The export did not finish.');
            });
    }

    function visibleColumns(api) {
        var headers = [];
        var indexes = [];
        api.columns().every(function (index) {
            var header = this.header();
            if (!header || !this.visible()) {
                return;
            }
            if (header.getAttribute('data-dt-order') === 'disable' || header.classList.contains('list-dt-actions')) {
                return;
            }
            var title = header.querySelector('.dt-column-title');
            var text = title && title.textContent ? title.textContent.trim() : '';
            headers.push(text);
            indexes.push(index);
        });
        return { headers: headers, indexes: indexes };
    }

    function queryString(params) {
        var pairs = [];
        flattenParams(params, '', pairs);
        return pairs.join('&');
    }

    function flattenParams(value, prefix, pairs) {
        if (value === null || value === undefined) {
            return;
        }
        if (Array.isArray(value)) {
            value.forEach(function (item, index) {
                flattenParams(item, prefix + '[' + index + ']', pairs);
            });
            return;
        }
        if (typeof value === 'object') {
            Object.keys(value).forEach(function (key) {
                var next = prefix === '' ? key : prefix + '[' + key + ']';
                flattenParams(value[key], next, pairs);
            });
            return;
        }
        pairs.push(encodeURIComponent(prefix) + '=' + encodeURIComponent(String(value)));
    }

    function deliverExport(kind, name, headers, rows) {
        if (kind === 'csv') {
            download(name + '.csv', 'text/csv;charset=utf-8', csvText(headers, rows));
            return Promise.resolve();
        }
        if (kind === 'print') {
            printTable(name, headers, rows);
            return Promise.resolve();
        }
        var ready = kind === 'pdf' ? ensurePdf() : ensureExcel();
        return ready.then(function () {
            exportWithButton(kind, name, headers, rows);
        });
    }

    function csvCell(value) {
        var text = String(value);
        var amount = /^-?\$?\d[\d,]*(?:\.\d+)?$/.test(text);
        if (!amount && /^[=+\-@]/.test(text)) {
            text = "'" + text;
        }
        if (/[",\r\n]/.test(text)) {
            return '"' + text.replace(/"/g, '""') + '"';
        }
        return text;
    }

    function csvText(headers, rows) {
        var lines = [headers.map(csvCell).join(',')];
        rows.forEach(function (row) {
            lines.push(row.map(csvCell).join(','));
        });
        return '\uFEFF' + lines.join('\r\n');
    }

    function download(filename, mime, body) {
        var blob = new Blob([body], { type: mime });
        var link = document.createElement('a');
        var url = URL.createObjectURL(blob);
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function printTable(name, headers, rows) {
        var popup = window.open('', '_blank');
        if (!popup) {
            return;
        }
        var head = headers.map(function (header) {
            return '<th>' + escapeHtml(header) + '</th>';
        }).join('');
        var body = rows.map(function (row) {
            return '<tr>' + row.map(function (cell) {
                return '<td>' + escapeHtml(cell) + '</td>';
            }).join('') + '</tr>';
        }).join('');
        popup.document.write('<!DOCTYPE html><title>' + escapeHtml(name) + '</title><table><thead><tr>' + head + '</tr></thead><tbody>' + body + '</tbody></table>');
        popup.document.close();
        popup.focus();
        popup.print();
    }

    function loadScript(src) {
        return new Promise(function (resolve, reject) {
            var existing = document.querySelector('script[src="' + src + '"]');
            if (existing !== null) {
                if (existing.getAttribute('data-loaded') === '1') {
                    resolve();
                    return;
                }
                existing.addEventListener('load', function () { resolve(); });
                existing.addEventListener('error', function () { reject(new Error(src)); });
                return;
            }
            var script = document.createElement('script');
            script.src = src;
            script.onload = function () {
                script.setAttribute('data-loaded', '1');
                resolve();
            };
            script.onerror = function () { reject(new Error(src)); };
            document.head.appendChild(script);
        });
    }

    function ensureExcel() {
        if (window.JSZip) {
            return Promise.resolve();
        }
        return loadScript('/assets/datatables/jszip.min.js');
    }

    function ensurePdf() {
        if (window.pdfMake && window.pdfMake.vfs) {
            return Promise.resolve();
        }
        return loadScript('/assets/datatables/pdfmake.min.js').then(function () {
            return loadScript('/assets/datatables/vfs_fonts.js');
        });
    }

    function exportWithButton(kind, name, headers, rows) {
        var holder = document.createElement('div');
        holder.hidden = true;
        var table = document.createElement('table');
        holder.appendChild(table);
        document.body.appendChild(holder);
        var api = new DataTable(table, {
            data: rows,
            columns: headers.map(function (title) { return { title: title }; }),
            paging: false,
            searching: false,
            info: false,
            ordering: false,
            dom: 'B',
            buttons: [{
                extend: kind,
                filename: name,
                orientation: 'landscape',
                pageSize: 'LETTER',
            }],
        });
        api.button(0).trigger();
        try {
            api.destroy();
        } catch (error) {
            // The file button detaches the temporary table as it builds the download.
        }
        if (holder.parentNode !== null) {
            holder.remove();
        }
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
        var scrollWrap = tableEl.closest('.list-dt-wrap');
        if (scrollWrap !== null) {
            scrollWrap.classList.add('list-dt-page-scroll');
        }
        var selectionUi = openInventorySelection(tableEl);
        var name = exportName(tableEl);
        var exportOptions = {
            columns: ':visible:not(.list-dt-actions)',
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
            pageLength: storedPageLength(),
            lengthMenu: [10, 25, 50, 100],
            scrollX: true,
            orderCellsTop: false,
            stripe: true,
            searchDelay: 300,
            layout: {
                topStart: ['pageLength', 'buttons'],
                topEnd: 'search',
                bottomStart: 'info',
                bottomEnd: 'paging',
            },
            buttons: [
                {
                    extend: 'colvis',
                    text: 'Columns',
                    columns: ':not(.list-dt-actions)',
                    columnText: columnTitle,
                    popoverTitle: 'Show or hide columns',
                },
                { extend: 'csv', filename: name, exportOptions: exportOptions },
                { extend: 'excel', filename: name, exportOptions: exportOptions },
                { extend: 'pdf', filename: name, orientation: 'landscape', pageSize: 'LETTER', exportOptions: exportOptions },
                { extend: 'print', exportOptions: exportOptions },
            ],
            columnDefs: columnDefs(tableEl),
            initComplete: function () {
                var api = this.api();
                applyStoredVisibility(tableEl, api);
                bindFilters(api, tableEl);
                api.columns.adjust();
                quietClonedFilters(tableEl);
            },
        };
        if (tableEl.getAttribute('data-dt-server') === '1') {
            applyServer(options, tableEl, name);
        }
        options.drawCallback = function () {
            quietClonedFilters(tableEl);
            if (selectionUi !== null) {
                selectionUi.draw();
            }
        };
        var api = new DataTable('#' + tableEl.id, options);
        api.on('column-visibility.dt', function () {
            rememberColumnVisibility(tableEl, api);
            api.columns.adjust();
        });
        return api;
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
        var applyingLength = false;
        instances.forEach(function (entry) {
            entry.api.on('length.dt', function (e, settings, len) {
                if (applyingLength) {
                    return;
                }
                var length = parseInt(len, 10);
                if (PAGE_LENGTHS.indexOf(length) === -1) {
                    return;
                }
                rememberPageLength(length);
                applyingLength = true;
                instances.forEach(function (other) {
                    if (other.api.page.len() !== length) {
                        other.api.page.len(length).draw(false);
                    }
                });
                applyingLength = false;
            });
        });
        var timer = 0;
        window.addEventListener('resize', function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(function () {
                instances.forEach(function (entry) {
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
