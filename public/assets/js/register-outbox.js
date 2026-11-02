(function () {
    const storageKey = 'stall-snaps';

    function read() {
        try {
            const stored = window.localStorage.getItem(storageKey);
            const parsed = stored ? JSON.parse(stored) : [];
            return Array.isArray(parsed) ? parsed : [];
        } catch (error) {
            return [];
        }
    }

    function write(rows) {
        window.localStorage.setItem(storageKey, JSON.stringify(rows));
    }

    window.stallHoldSnap = function (form) {
        const posting = document.getElementById('sale-posting');
        let lines = [];
        try {
            lines = JSON.parse(form.getAttribute('data-snaps') || '[]');
        } catch (error) {
            lines = [];
        }
        if (!Array.isArray(lines)) {
            lines = [];
        }
        const root = String(form.action || '').replace(/\/pos\/pay$/, '');
        const queued = read();
        lines.forEach(function (line) {
            if (!line || !line.posting_key) {
                return;
            }
            queued.push({
                posting_key: line.posting_key,
                action: root
            });
        });
        write(queued);
        if (posting) {
            posting.textContent = 'This sale is not posted.';
        }
    };

    function token() {
        const input = document.querySelector('input[name="csrf"]');
        return input ? input.value : '';
    }

    function send(row) {
        const sync = new FormData();
        sync.set('csrf', token());
        sync.set('posting_key', row.posting_key);
        return fetch(row.action + '/pos/sync', {
            method: 'POST',
            body: sync,
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        });
    }

    read().forEach(function (row) {
        if (!row || !row.action || !row.posting_key) {
            return;
        }
        const rest = read().filter(function (kept) {
            return kept.posting_key !== row.posting_key;
        });
        write(rest);
        send(row).catch(function () {
            const again = read();
            again.push(row);
            write(again);
        });
    });
}());
