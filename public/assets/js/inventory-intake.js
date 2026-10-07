(function () {
    'use strict';

    let form = document.getElementById('piece-form');
    if (!form) {
        return;
    }

    let vendorId = document.getElementById('vendor_id');
    let vendorLabel = document.getElementById('vendor_label');
    let vendorSuggest = document.getElementById('vendor_suggest');
    let vendorError = document.getElementById('vendor_client_error');
    let brandId = document.getElementById('brand_id');
    let brandLabel = document.getElementById('brand_label');
    let brandSuggest = document.getElementById('brand_suggest');
    let brandCreate = document.getElementById('brand_create');
    let brandCreateName = document.getElementById('brand_create_name');
    let brandCreateButton = document.getElementById('brand_create_button');
    let brandCreateError = document.getElementById('brand_create_error');
    let storeTake = document.getElementById('store_take');
    let storeBadge = document.getElementById('store_take_badge');
    let storeDefault = document.getElementById('store_take_default');
    let intake = document.getElementById('intake_on');
    let expires = document.getElementById('expires_on');
    if (!vendorId || !vendorLabel || !vendorSuggest || !brandId || !brandLabel || !brandSuggest || !storeTake || !intake || !expires) {
        return;
    }

    let vendorsUrl = form.getAttribute('data-vendors') || '';
    let brandsUrl = form.getAttribute('data-brands') || '';
    let brandCreateUrl = form.getAttribute('data-brand-create') || '';
    let floorDays = parseInt(form.getAttribute('data-floor-days') || '', 10);
    let takeDirty = form.getAttribute('data-take-dirty') === '1';
    let expireDirty = form.getAttribute('data-expire-dirty') === '1';
    let vendorTimer = null;
    let brandTimer = null;

    function points(text) {
        let match = /^(\d{1,3})(?:\.(\d{1,2}))?$/.exec(String(text || '').trim());
        if (!match) {
            return null;
        }
        let fraction = (match[2] || '').padEnd(2, '0');
        let basis = (parseInt(match[1], 10) * 100) + parseInt(fraction, 10);
        if (basis > 10000) {
            return null;
        }
        return basis;
    }

    function paintTake() {
        if (!storeBadge) {
            return;
        }
        let current = points(storeTake.value);
        let standard = points(form.getAttribute('data-default-take') || '');
        let overridden = current !== null && standard !== null && current !== standard;
        storeBadge.hidden = !overridden;
    }

    function addDays(iso, days) {
        let parts = iso.split('-');
        if (parts.length !== 3) {
            return '';
        }
        let date = new Date(Date.UTC(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2])));
        if (Number.isNaN(date.getTime())) {
            return '';
        }
        date.setUTCDate(date.getUTCDate() + days);
        return date.toISOString().slice(0, 10);
    }

    function paintExpire() {
        if (intake.value !== '') {
            expires.min = intake.value;
        }
        if (expireDirty || !(floorDays >= 1 && floorDays <= 365) || intake.value === '') {
            return;
        }
        let next = addDays(intake.value, floorDays);
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
            let button = document.createElement('button');
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
                render(vendorSuggest, vendorId, vendorLabel, rows, function (row) {
                    return row.label || '';
                }, function (row) {
                    let take = row.getAttribute('data-take') || '';
                    form.setAttribute('data-default-take', take);
                    if (storeDefault) {
                        storeDefault.textContent = take;
                    }
                    if (!takeDirty) {
                        storeTake.value = take;
                    }
                    paintTake();
                    if (vendorError) {
                        vendorError.hidden = true;
                    }
                });
            })
            .catch(function () { hide(vendorSuggest); });
    }

    function showBrandCreate(name) {
        if (!brandCreate || !brandCreateName) {
            return;
        }
        brandCreateName.textContent = name;
        brandCreate.hidden = false;
    }

    function searchBrands(query) {
        fetch(brandsUrl + '?q=' + encodeURIComponent(query), { credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (rows) {
                if (!Array.isArray(rows)) {
                    rows = [];
                }
                if (brandCreate) {
                    brandCreate.hidden = true;
                }
                if (rows.length === 0 && query !== '') {
                    hide(brandSuggest);
                    showBrandCreate(query);
                    return;
                }
                render(brandSuggest, brandId, brandLabel, rows, function (row) {
                    return row.name || '';
                }, function () {
                    if (brandCreate) {
                        brandCreate.hidden = true;
                    }
                });
            })
            .catch(function () { hide(brandSuggest); });
    }

    if (form.getAttribute('data-sold') !== '1') {
        vendorLabel.addEventListener('input', function () {
            vendorId.value = '';
            if (vendorError) {
                vendorError.hidden = true;
            }
            if (vendorTimer) {
                clearTimeout(vendorTimer);
            }
            let query = vendorLabel.value.trim();
            if (query === '') {
                hide(vendorSuggest);
                return;
            }
            vendorTimer = setTimeout(function () { searchVendors(query); }, 200);
        });
    }

    brandLabel.addEventListener('input', function () {
        brandId.value = '';
        if (brandCreateError) {
            brandCreateError.hidden = true;
        }
        if (brandTimer) {
            clearTimeout(brandTimer);
        }
        let query = brandLabel.value.trim();
        if (query === '') {
            hide(brandSuggest);
            if (brandCreate) {
                brandCreate.hidden = true;
            }
            return;
        }
        brandTimer = setTimeout(function () { searchBrands(query); }, 200);
    });

    storeTake.addEventListener('input', function () {
        takeDirty = true;
        paintTake();
    });

    intake.addEventListener('change', paintExpire);
    expires.addEventListener('input', function () {
        expireDirty = true;
    });

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

    if (brandCreateButton) {
        brandCreateButton.addEventListener('click', function () {
            let name = brandLabel.value.trim();
            if (name === '' || !brandCreateUrl) {
                return;
            }
            let token = form.querySelector('input[name="csrf"]');
            let body = new URLSearchParams();
            body.set('csrf', token ? token.value : '');
            body.set('name', name);
            fetch(brandCreateUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
                .then(function (response) {
                    return response.json().then(function (payload) {
                        return { ok: response.ok, payload: payload };
                    });
                })
                .then(function (result) {
                    if (!result.ok || !result.payload || !result.payload.id) {
                        if (brandCreateError) {
                            brandCreateError.textContent = (result.payload && result.payload.error) || 'That brand was not added.';
                            brandCreateError.hidden = false;
                        }
                        return;
                    }
                    brandId.value = result.payload.id;
                    brandLabel.value = result.payload.name || name;
                    if (brandCreate) {
                        brandCreate.hidden = true;
                    }
                    if (brandCreateError) {
                        brandCreateError.hidden = true;
                    }
                })
                .catch(function () {
                    if (brandCreateError) {
                        brandCreateError.textContent = 'That brand was not added.';
                        brandCreateError.hidden = false;
                    }
                });
        });
    }

    if (brandId.value === '' && brandLabel.value.trim() !== '') {
        searchBrands(brandLabel.value.trim());
    }
    form.querySelectorAll('[data-name-list]').forEach(function (box) {
        wireNameList(box, form);
    });
    paintTake();
    paintExpire();

    function wireNameList(box, host) {
        let label = box.querySelector('input[type="text"], input.input:not([type="hidden"])');
        let hidden = box.querySelector('input[type="hidden"]');
        let suggest = box.querySelector('.suggest');
        let create = box.querySelector('[data-create-row]');
        let createName = box.querySelector('[data-create-name]');
        let createButton = box.querySelector('[data-create-button]');
        let error = box.querySelector('.field-error');
        let searchUrl = box.getAttribute('data-search') || '';
        let createUrl = box.getAttribute('data-create') || '';
        let timer = null;
        if (!label || !hidden || !suggest) {
            return;
        }
        function hideSuggest() {
            suggest.hidden = true;
            suggest.replaceChildren();
        }
        function search(query) {
            fetch(searchUrl + '?q=' + encodeURIComponent(query), { credentials: 'same-origin' })
                .then(function (response) { return response.json(); })
                .then(function (rows) {
                    if (!Array.isArray(rows)) {
                        return;
                    }
                    if (create) {
                        create.hidden = true;
                    }
                    if (rows.length === 0) {
                        hideSuggest();
                        if (create && createName && query !== '') {
                            createName.textContent = query;
                            create.hidden = false;
                        }
                        return;
                    }
                    suggest.replaceChildren();
                    rows.forEach(function (row) {
                        let button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'suggest-item';
                        button.textContent = row.name || '';
                        button.addEventListener('click', function () {
                            hidden.value = row.id || '';
                            label.value = row.name || '';
                            hideSuggest();
                            if (create) {
                                create.hidden = true;
                            }
                        });
                        suggest.appendChild(button);
                    });
                    suggest.hidden = false;
                })
                .catch(hideSuggest);
        }
        label.addEventListener('input', function () {
            hidden.value = '';
            if (error) {
                error.hidden = true;
            }
            if (timer) {
                clearTimeout(timer);
            }
            let query = label.value.trim();
            if (query === '') {
                hideSuggest();
                if (create) {
                    create.hidden = true;
                }
                return;
            }
            timer = setTimeout(function () { search(query); }, 200);
        });
        if (createButton) {
            createButton.addEventListener('click', function () {
                let name = label.value.trim();
                if (name === '' || createUrl === '') {
                    return;
                }
                let token = host.querySelector('input[name="csrf"]');
                let body = new URLSearchParams();
                body.set('csrf', token ? token.value : '');
                body.set('name', name);
                fetch(createUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                })
                    .then(function (response) {
                        return response.json().then(function (payload) {
                            return { ok: response.ok, payload: payload };
                        });
                    })
                    .then(function (result) {
                        if (!result.ok || !result.payload || !result.payload.id) {
                            if (error) {
                                error.textContent = (result.payload && result.payload.error) || 'That name was not added.';
                                error.hidden = false;
                            }
                            return;
                        }
                        hidden.value = result.payload.id;
                        label.value = result.payload.name || name;
                        if (create) {
                            create.hidden = true;
                        }
                        if (error) {
                            error.hidden = true;
                        }
                    })
                    .catch(function () {
                        if (error) {
                            error.textContent = 'That name was not added.';
                            error.hidden = false;
                        }
                    });
            });
        }
    }
}());
