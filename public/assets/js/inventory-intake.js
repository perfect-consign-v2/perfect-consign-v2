(function () {
    'use strict';

    const form = document.getElementById('item-form');
    if (!form) {
        return;
    }

    const vendorId = document.getElementById('vendor_id');
    const vendorLabel = document.getElementById('vendor_label');
    const vendorSuggest = document.getElementById('vendor_suggest');
    const vendorError = document.getElementById('vendor_client_error');
    const storeTake = document.getElementById('store_take');
    const storeBadge = document.getElementById('store_take_badge');
    const storeDefault = document.getElementById('store_take_default');
    const intake = document.getElementById('intake_on');
    const expires = document.getElementById('expires_on');
    const vendorsUrl = form.getAttribute('data-vendors') || '';
    const floorDays = parseInt(form.getAttribute('data-floor-days') || '', 10);
    let takeDirty = form.getAttribute('data-take-dirty') === '1';
    let expireDirty = form.getAttribute('data-expire-dirty') === '1';
    let vendorTimer = null;

    function points(text) {
        const match = /^(\d{1,3})(?:\.(\d{1,2}))?$/.exec(String(text || '').trim());
        if (!match) {
            return null;
        }
        const fraction = (match[2] || '').padEnd(2, '0');
        const basis = (parseInt(match[1], 10) * 100) + parseInt(fraction, 10);
        if (basis > 10000) {
            return null;
        }
        return basis;
    }

    function paintTake() {
        if (!storeTake || !storeBadge) {
            return;
        }
        const current = points(storeTake.value);
        const standard = points(form.getAttribute('data-default-take') || '');
        const overridden = current !== null && standard !== null && current !== standard;
        storeBadge.hidden = !overridden;
    }

    function addDays(iso, days) {
        const parts = iso.split('-');
        if (parts.length !== 3) {
            return '';
        }
        const date = new Date(Date.UTC(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2])));
        if (Number.isNaN(date.getTime())) {
            return '';
        }
        date.setUTCDate(date.getUTCDate() + days);
        return date.toISOString().slice(0, 10);
    }

    function paintExpire() {
        if (!intake || !expires) {
            return;
        }
        if (intake.value !== '') {
            expires.min = intake.value;
        }
        if (expireDirty || !(floorDays >= 1 && floorDays <= 365) || intake.value === '') {
            return;
        }
        const next = addDays(intake.value, floorDays);
        if (next !== '') {
            expires.value = next;
        }
    }

    function hide(panel) {
        panel.hidden = true;
        panel.innerHTML = '';
    }

    function choose(panel, hidden, input, row, after) {
        hidden.value = row.getAttribute('data-id') || '';
        input.value = row.getAttribute('data-label') || row.textContent || '';
        hide(panel);
        if (after) {
            after(row);
        }
    }

    function render(panel, hidden, input, rows, labelOf, after) {
        panel.innerHTML = '';
        rows.forEach(function (row) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'suggest-item';
            button.setAttribute('data-id', row.id || '');
            button.setAttribute('data-label', labelOf(row));
            button.textContent = labelOf(row);
            if (row.take) {
                button.setAttribute('data-take', row.take);
            }
            button.addEventListener('mousedown', function (event) {
                event.preventDefault();
                choose(panel, hidden, input, button, after);
            });
            panel.appendChild(button);
        });
        panel.hidden = rows.length === 0;
    }

    function searchVendors(query) {
        fetch(vendorsUrl + '?q=' + encodeURIComponent(query), { credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (rows) {
                if (!Array.isArray(rows)) {
                    rows = [];
                }
                if (!vendorSuggest || !vendorId || !vendorLabel) {
                    return;
                }
                render(vendorSuggest, vendorId, vendorLabel, rows, function (row) {
                    return row.label || '';
                }, function (row) {
                    const take = row.getAttribute('data-take') || '';
                    form.setAttribute('data-default-take', take);
                    if (storeDefault) {
                        storeDefault.textContent = take;
                    }
                    if (!takeDirty && storeTake) {
                        storeTake.value = take;
                    }
                    paintTake();
                    if (vendorError) {
                        vendorError.hidden = true;
                    }
                });
            })
            .catch(function () {
                if (vendorSuggest) {
                    hide(vendorSuggest);
                }
            });
    }

    if (vendorId && vendorLabel && vendorSuggest && form.getAttribute('data-sold') !== '1') {
        vendorLabel.addEventListener('input', function () {
            vendorId.value = '';
            if (vendorError) {
                vendorError.hidden = true;
            }
            if (vendorTimer) {
                clearTimeout(vendorTimer);
            }
            const query = vendorLabel.value.trim();
            if (query === '') {
                hide(vendorSuggest);
                return;
            }
            vendorTimer = setTimeout(function () { searchVendors(query); }, 200);
        });
    }

    if (storeTake) {
        storeTake.addEventListener('input', function () {
            takeDirty = true;
            paintTake();
        });
        paintTake();
    }

    if (intake && expires) {
        intake.addEventListener('change', paintExpire);
        expires.addEventListener('input', function () {
            expireDirty = true;
        });
        paintExpire();
    }

    if (vendorId && vendorLabel) {
        form.addEventListener('submit', function (event) {
            if (vendorId.value !== '') {
                return;
            }
            event.preventDefault();
            if (vendorError) {
                vendorError.hidden = false;
            }
            vendorLabel.focus();
        });
    }
}());
