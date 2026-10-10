'use strict';

const test = require('node:test');
const assert = require('node:assert');
const { createInventorySelectionStore } = require('../../public/assets/js/inventory-selection-store.js');

const PAGE_ONE = [
    'aaaaaaaa-1111-2222-3333-444444444444',
    'bbbbbbbb-1111-2222-3333-444444444444',
];
const PAGE_TWO = [
    'cccccccc-1111-2222-3333-444444444444',
];

/**
 * @param {Record<string, string>} [initial]
 * @returns {Storage}
 */
function memoryStorage(initial) {
    const data = Object.assign({}, initial || {});

    return {
        getItem: function (key) {
            return Object.prototype.hasOwnProperty.call(data, key) ? data[key] : null;
        },
        setItem: function (key, value) {
            data[key] = String(value);
        },
        removeItem: function (key) {
            delete data[key];
        },
    };
}

test('page two does not erase page one', function () {
    const store = createInventorySelectionStore({ storage: memoryStorage(), storageKey: 'sel' });
    store.applyPage(PAGE_ONE, true);
    store.applyPage(PAGE_TWO, true);

    assert.deepStrictEqual(store.snapshot(), PAGE_ONE.concat(PAGE_TWO));
    assert.strictEqual(store.count(), 3);
});

test('uncheck and applyPage false remove only those ids', function () {
    const store = createInventorySelectionStore({ storage: memoryStorage(), storageKey: 'sel' });
    store.applyPage(PAGE_ONE.concat(PAGE_TWO), true);
    store.setChecked(PAGE_ONE[1], false);
    store.applyPage(PAGE_TWO, false);

    assert.deepStrictEqual(store.snapshot(), [PAGE_ONE[0]]);
    assert.strictEqual(store.isChecked(PAGE_ONE[1]), false);
    assert.strictEqual(store.isChecked(PAGE_TWO[0]), false);
    assert.strictEqual(store.isChecked(PAGE_ONE[0]), true);
});

test('checking the same id twice does not duplicate it', function () {
    const store = createInventorySelectionStore({ storage: memoryStorage(), storageKey: 'sel' });
    store.setChecked(PAGE_ONE[0], true);
    store.setChecked(PAGE_ONE[0], true);
    store.applyPage([PAGE_ONE[0], PAGE_ONE[0]], true);

    assert.deepStrictEqual(store.snapshot(), [PAGE_ONE[0]]);
    assert.strictEqual(store.count(), 1);
});

test('load and save round-trip and bad JSON stays empty', function () {
    const storage = memoryStorage();
    const store = createInventorySelectionStore({ storage: storage, storageKey: 'sel' });
    store.setChecked(PAGE_ONE[0], true);
    store.setChecked(PAGE_ONE[1], true);
    store.save();

    const restored = createInventorySelectionStore({ storage: storage, storageKey: 'sel' });
    restored.load();
    assert.deepStrictEqual(restored.snapshot(), [PAGE_ONE[0], PAGE_ONE[1]]);

    storage.setItem('sel', JSON.stringify([PAGE_ONE[0], PAGE_ONE[0], '  ']));
    restored.load();
    assert.deepStrictEqual(restored.snapshot(), [PAGE_ONE[0]]);

    storage.setItem('sel', '{not json');
    restored.load();
    assert.deepStrictEqual(restored.snapshot(), []);
    assert.strictEqual(restored.count(), 0);

    storage.setItem('sel', '{"id":"x"}');
    restored.load();
    assert.deepStrictEqual(restored.snapshot(), []);
});

test('clear empties the store and persists an empty list', function () {
    const storage = memoryStorage();
    const store = createInventorySelectionStore({ storage: storage, storageKey: 'sel' });
    store.applyPage(PAGE_ONE, true);
    store.clear();

    assert.deepStrictEqual(store.snapshot(), []);
    assert.strictEqual(storage.getItem('sel'), '[]');

    const restored = createInventorySelectionStore({ storage: storage, storageKey: 'sel' });
    restored.load();
    assert.deepStrictEqual(restored.snapshot(), []);
});
