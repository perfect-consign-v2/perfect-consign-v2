'use strict';

/**
 * Ordered piece-id selection for sticker printing.
 * Checks survive paging. Copy count stays on the sticker form.
 *
 * @param {{ storage?: Storage, storageKey?: string }} opts
 * @return {{
 *   setChecked: (id: string, checked: boolean) => void,
 *   isChecked: (id: string) => boolean,
 *   applyPage: (ids: string[], checked: boolean) => void,
 *   count: () => number,
 *   clear: () => void,
 *   snapshot: () => string[],
 *   load: () => void,
 *   save: () => void
 * }}
 */
function createInventorySelectionStore(opts) {
    var storage = opts && opts.storage ? opts.storage : null;
    var storageKey = opts && typeof opts.storageKey === 'string' ? opts.storageKey : '';
    /** @type {string[]} */
    var order = [];
    /** @type {Record<string, true>} */
    var selected = Object.create(null);

    /**
     * @param {unknown} id
     * @returns {string}
     */
    function normalizeId(id) {
        if (typeof id !== 'string') {
            return '';
        }

        return id.trim();
    }

    /**
     * @param {string} id
     * @returns {boolean}
     */
    function hasId(id) {
        return Object.prototype.hasOwnProperty.call(selected, id);
    }

    /**
     * @param {string} id
     * @param {boolean} checked
     */
    function setChecked(id, checked) {
        var key = normalizeId(id);
        if (key === '') {
            return;
        }
        if (checked) {
            if (hasId(key)) {
                return;
            }
            selected[key] = true;
            order.push(key);

            return;
        }
        if (!hasId(key)) {
            return;
        }
        delete selected[key];
        var index = order.indexOf(key);
        if (index >= 0) {
            order.splice(index, 1);
        }
    }

    /**
     * @param {string} id
     * @returns {boolean}
     */
    function isChecked(id) {
        var key = normalizeId(id);

        return key !== '' && hasId(key);
    }

    /**
     * @param {string[]} ids
     * @param {boolean} checked
     */
    function applyPage(ids, checked) {
        if (!Array.isArray(ids)) {
            return;
        }
        ids.forEach(function (id) {
            setChecked(id, checked === true);
        });
    }

    /** @returns {number} */
    function count() {
        return order.length;
    }

    /** @returns {string[]} */
    function snapshot() {
        return order.filter(function (id) {
            return hasId(id);
        });
    }

    function canUseStorage() {
        return storage !== null && storageKey !== '' && typeof storage.getItem === 'function' && typeof storage.setItem === 'function';
    }

    function save() {
        if (!canUseStorage()) {
            return;
        }
        try {
            storage.setItem(storageKey, JSON.stringify(snapshot()));
        } catch (err) {
            return;
        }
    }

    function clear() {
        order.length = 0;
        selected = Object.create(null);
        save();
    }

    function load() {
        order.length = 0;
        selected = Object.create(null);
        if (!canUseStorage()) {
            return;
        }
        var raw = null;
        try {
            raw = storage.getItem(storageKey);
        } catch (err) {
            return;
        }
        if (typeof raw !== 'string' || raw === '') {
            return;
        }
        var parsed;
        try {
            parsed = JSON.parse(raw);
        } catch (err) {
            return;
        }
        if (!Array.isArray(parsed)) {
            return;
        }
        parsed.forEach(function (entry) {
            var id = normalizeId(entry);
            if (id === '' || hasId(id)) {
                return;
            }
            selected[id] = true;
            order.push(id);
        });
    }

    return {
        setChecked: setChecked,
        isChecked: isChecked,
        applyPage: applyPage,
        count: count,
        clear: clear,
        snapshot: snapshot,
        load: load,
        save: save,
    };
}

/**
 * @param {HTMLElement} tableEl
 * @param {{ isChecked: (id: string) => boolean }} selection
 */
function restoreChecks(tableEl, selection) {
    tableEl.querySelectorAll('tbody input[type="checkbox"][name="item[]"]').forEach(function (box) {
        if (!(box instanceof HTMLInputElement)) {
            return;
        }
        box.checked = selection.isChecked(box.value);
    });
}

/**
 * @param {HTMLElement} tableEl
 * @returns {string[]}
 */
function selectableIds(tableEl) {
    /** @type {string[]} */
    var ids = [];
    tableEl.querySelectorAll('tbody input[type="checkbox"][name="item[]"]').forEach(function (box) {
        if (box instanceof HTMLInputElement && box.value !== '') {
            ids.push(box.value);
        }
    });

    return ids;
}

/**
 * @param {HTMLElement} tableEl
 */
function syncSelectAll(tableEl) {
    var boxes = tableEl.querySelectorAll('tbody input[type="checkbox"][name="item[]"]');
    var checked = 0;
    boxes.forEach(function (box) {
        if (box instanceof HTMLInputElement && box.checked) {
            checked += 1;
        }
    });
    var all = boxes.length > 0 && checked === boxes.length;
    var some = checked > 0 && checked < boxes.length;
    tableEl.querySelectorAll('.list-dt-select-all').forEach(function (input) {
        if (!(input instanceof HTMLInputElement)) {
            return;
        }
        input.checked = all;
        input.indeterminate = some;
    });
}

/**
 * @param {HTMLElement} tableEl
 */
function uncheckVisible(tableEl) {
    tableEl.querySelectorAll('tbody input[type="checkbox"][name="item[]"]').forEach(function (box) {
        if (box instanceof HTMLInputElement) {
            box.checked = false;
        }
    });
}

/**
 * @param {HTMLFormElement} form
 * @param {ReturnType<typeof createInventorySelectionStore>} selection
 * @param {{ restore: () => void, syncSelectAll: () => void, uncheckVisible: () => void }} list
 * @return {{ syncBar: () => void, refresh: () => void }}
 */
function bindSelectionChrome(form, selection, list) {
    function syncBar() {
        var bar = document.querySelector('[data-inventory-selection-bar]');
        if (!(bar instanceof HTMLElement)) {
            return;
        }
        var n = selection.count();
        bar.hidden = n === 0;
        var countEl = bar.querySelector('[data-inventory-selection-count]');
        if (countEl !== null) {
            countEl.textContent = n === 1 ? '1 selected' : String(n) + ' selected';
        }
    }

    function refresh() {
        list.restore();
        list.syncSelectAll();
        syncBar();
    }

    function releaseFields() {
        form.querySelectorAll('[data-selection-injected="1"]').forEach(function (field) {
            field.remove();
        });
        document.querySelectorAll('input[type="checkbox"][name="item[]"]').forEach(function (box) {
            if (box instanceof HTMLInputElement && box.form === form) {
                box.disabled = false;
            }
        });
    }

    if (form.getAttribute('data-inventory-selection-bound') !== '1') {
        form.setAttribute('data-inventory-selection-bound', '1');
        form.addEventListener('submit', function () {
            document.querySelectorAll('input[type="checkbox"][name="item[]"]').forEach(function (box) {
                if (box instanceof HTMLInputElement && box.form === form) {
                    box.disabled = true;
                }
            });
            form.querySelectorAll('[data-selection-injected="1"]').forEach(function (field) {
                field.remove();
            });
            selection.snapshot().forEach(function (id) {
                var field = document.createElement('input');
                field.type = 'hidden';
                field.name = 'item[]';
                field.value = id;
                field.setAttribute('data-selection-injected', '1');
                form.appendChild(field);
            });
            selection.save();
        });
        var clearBtn = document.querySelector('[data-inventory-selection-clear]');
        if (clearBtn !== null) {
            clearBtn.addEventListener('click', function () {
                selection.clear();
                list.uncheckVisible();
                list.syncSelectAll();
                syncBar();
            });
        }
        window.addEventListener('pageshow', function () {
            selection.load();
            releaseFields();
            refresh();
        });
    }

    return {
        syncBar: syncBar,
        refresh: refresh,
    };
}

function bootPlainLists() {
    document.querySelectorAll('table[data-inventory-selection="plain"]').forEach(function (tableEl) {
        if (!(tableEl instanceof HTMLElement) || tableEl.getAttribute('data-inventory-selection-bound') === '1') {
            return;
        }
        var form = document.getElementById('stickers');
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        var keyInput = form.querySelector('input[name="selection_storage_key"]');
        var storageKey = keyInput instanceof HTMLInputElement ? keyInput.value : '';
        if (storageKey === '') {
            return;
        }
        tableEl.setAttribute('data-inventory-selection-bound', '1');
        var selection = createInventorySelectionStore({
            storage: window.sessionStorage,
            storageKey: storageKey,
        });
        selection.load();
        var chrome = bindSelectionChrome(form, selection, {
            restore: function () {
                restoreChecks(tableEl, selection);
            },
            syncSelectAll: function () {
                syncSelectAll(tableEl);
            },
            uncheckVisible: function () {
                uncheckVisible(tableEl);
            },
        });
        tableEl.addEventListener('change', function (event) {
            var target = event.target;
            if (!(target instanceof HTMLInputElement) || target.type !== 'checkbox' || target.name !== 'item[]') {
                return;
            }
            selection.setChecked(target.value, target.checked);
            selection.save();
            chrome.syncBar();
        });
        chrome.refresh();
    });
}

var inventorySelectionApi = {
    createInventorySelectionStore: createInventorySelectionStore,
    bindSelectionChrome: bindSelectionChrome,
    restoreChecks: restoreChecks,
    selectableIds: selectableIds,
    syncSelectAll: syncSelectAll,
    uncheckVisible: uncheckVisible,
};

if (typeof globalThis !== 'undefined') {
    globalThis.InventorySelectionStore = inventorySelectionApi;
}
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        createInventorySelectionStore: createInventorySelectionStore,
    };
}
if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootPlainLists);
    } else {
        bootPlainLists();
    }
}
