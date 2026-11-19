(function () {
    'use strict';

    const scope = document.getElementById('scope');
    const who = document.getElementById('funded_by');
    if (scope && who) {
        scope.addEventListener('change', function () {
            who.value = scope.value === 'store' ? 'store' : 'vendor';
        });
    }

    const kind = document.getElementById('kind');
    const priceLabel = document.getElementById('price-label');
    const kindHint = document.getElementById('kind-hint');
    function showKind() {
        if (!kind || !priceLabel || !kindHint) {
            return;
        }
        const each = kind.value === 'unit_break';
        priceLabel.textContent = each ? 'Price each' : 'Price for the bunch';
        kindHint.textContent = each
            ? 'Once they buy that many, each one rings at this price.'
            : 'One or two still ring at the tag price.';
    }
    if (kind) {
        kind.addEventListener('change', showKind);
        showKind();
    }

    const box = document.getElementById('item-lookup');
    const label = document.getElementById('item_label');
    const hidden = document.getElementById('item_code');
    const suggest = box ? box.querySelector('.suggest') : null;
    const searchUrl = box ? (box.getAttribute('data-search') || '') : '';
    if (!box || !scope || !label || !hidden || !suggest || searchUrl === '') {
        return;
    }

    let timer = null;
    let requestId = 0;

    function hideSuggest() {
        suggest.hidden = true;
        suggest.replaceChildren();
    }

    function paint(rows) {
        if (!Array.isArray(rows) || rows.length === 0) {
            hideSuggest();
            return;
        }
        suggest.replaceChildren();
        rows.forEach(function (row) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'suggest-item';
            button.textContent = row.name || '';
            button.addEventListener('mousedown', function (event) {
                event.preventDefault();
                hidden.value = row.code || '';
                label.value = row.name || '';
                hideSuggest();
            });
            suggest.appendChild(button);
        });
        suggest.hidden = false;
    }

    function search(query) {
        const ticket = ++requestId;
        const joiner = searchUrl.indexOf('?') === -1 ? '?' : '&';
        fetch(searchUrl + joiner + 'q=' + encodeURIComponent(query), { credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (rows) {
                if (ticket !== requestId) {
                    return;
                }
                paint(rows);
            })
            .catch(function () {
                if (ticket === requestId) {
                    hideSuggest();
                }
            });
    }

    function schedule(query) {
        if (timer) {
            clearTimeout(timer);
        }
        timer = setTimeout(function () { search(query); }, 200);
    }

    label.addEventListener('input', function () {
        hidden.value = label.value.trim();
        schedule(label.value.trim());
    });

    label.addEventListener('focus', function () {
        const showingChoice = hidden.value !== '' && label.value.trim() !== hidden.value;
        schedule(showingChoice ? hidden.value : label.value.trim());
    });

    scope.addEventListener('change', function () {
        box.hidden = scope.value !== 'item';
        if (box.hidden) {
            hideSuggest();
        }
    });

    document.addEventListener('click', function (event) {
        if (!box.contains(event.target)) {
            hideSuggest();
        }
    });
}());
