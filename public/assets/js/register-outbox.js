(function () {
    var storageKey = 'stall-snaps';

    function read() {
        try {
            var stored = window.localStorage.getItem(storageKey);
            var parsed = stored ? JSON.parse(stored) : [];
            return Array.isArray(parsed) ? parsed : [];
        } catch (error) {
            return [];
        }
    }

    function write(rows) {
        window.localStorage.setItem(storageKey, JSON.stringify(rows));
    }

    function uuid() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        var hex = Math.random().toString(16).slice(2) + Math.random().toString(16).slice(2);
        return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-4' + hex.slice(13, 16) + '-8' + hex.slice(17, 20) + '-' + hex.slice(20, 32);
    }

    window.stallHoldSnap = function (form) {
        var posting = document.getElementById('sale-posting');
        var lines = [];
        try {
            lines = JSON.parse(form.getAttribute('data-snaps') || '[]');
        } catch (error) {
            lines = [];
        }
        if (!Array.isArray(lines)) {
            lines = [];
        }
        var root = String(form.action || '').replace(/\/pos\/pay$/, '');
        var queued = read();
        lines.forEach(function (line) {
            if (!line || !line.item_id) {
                return;
            }
            queued.push({
                posting_key: uuid(),
                item_id: line.item_id,
                price: line.price,
                tax: line.tax,
                commission: line.commission,
                quantity: line.quantity,
                commission_bps: line.commission_bps,
                tax_bps: line.tax_bps,
                action: root
            });
        });
        write(queued);
        if (posting) {
            posting.textContent = 'This sale is not posted.';
        }
    };

    function token() {
        var input = document.querySelector('input[name="csrf"]');
        return input ? input.value : '';
    }

    function send(row) {
        var snap = new FormData();
        snap.set('csrf', token());
        snap.set('posting_key', row.posting_key);
        snap.set('item_id', row.item_id);
        snap.set('price', row.price);
        snap.set('tax', row.tax || '0.00');
        snap.set('commission', row.commission || '0.00');
        snap.set('quantity', row.quantity || '1');
        snap.set('commission_bps', row.commission_bps || '0');
        snap.set('tax_bps', row.tax_bps || '0');
        return fetch(row.action + '/pos/snap', {
            method: 'POST',
            body: snap,
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        }).then(function () {
            var sync = new FormData();
            sync.set('csrf', token());
            sync.set('posting_key', row.posting_key);
            return fetch(row.action + '/pos/sync', {
                method: 'POST',
                body: sync,
                credentials: 'same-origin',
                headers: { Accept: 'application/json' }
            });
        });
    }

    read().forEach(function (row) {
        if (!row || !row.action || !row.posting_key) {
            return;
        }
        var rest = read().filter(function (kept) {
            return kept.posting_key !== row.posting_key;
        });
        write(rest);
        send(row).catch(function () {
            var again = read();
            again.push(row);
            write(again);
        });
    });
}());
