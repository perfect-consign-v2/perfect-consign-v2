<script>
function vendorPick(input, hidden, list, url, onPick) {
    if (!input || !hidden || !list) {
        return;
    }
    let timer = null;
    input.addEventListener('input', function () {
        hidden.value = '';
        clearTimeout(timer);
        const q = input.value.trim();
        if (q.length < 1) {
            list.hidden = true;
            return;
        }
        timer = setTimeout(function () {
            fetch(url + '?q=' + encodeURIComponent(q)).then(function (response) {
                return response.json();
            }).then(function (rows) {
                list.replaceChildren();
                rows.forEach(function (row) {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'suggest-item';
                    button.textContent = row.label;
                    button.addEventListener('mousedown', function (event) {
                        event.preventDefault();
                        input.value = row.label;
                        hidden.value = row.id;
                        list.hidden = true;
                        if (onPick) {
                            onPick();
                        }
                    });
                    list.appendChild(button);
                });
                list.hidden = rows.length === 0;
            });
        }, 200);
    });
}
const scanField = document.getElementById('scan');
const scanList = document.getElementById('scan-suggest');
const instantOpen = document.getElementById('instant-add');
const giftOpen = document.getElementById('gift-sell');
if (instantOpen && !instantOpen.hidden) {
    const price = document.getElementById('instant_price');
    const consignor = document.getElementById('instant_vendor');
    const focus = price && price.getAttribute('aria-invalid') === 'true' ? price : consignor;
    if (focus) {
        focus.focus();
    }
} else if (scanField && scanList && !scanField.disabled) {
    if (!giftOpen || giftOpen.hidden) {
        scanField.focus();
    }
    let scanTimer = null;
    scanField.addEventListener('input', function () {
        clearTimeout(scanTimer);
        const q = scanField.value.trim();
        if (q.length < 1) {
            scanList.hidden = true;
            return;
        }
        scanTimer = setTimeout(function () {
            fetch(scanList.getAttribute('data-url') + '?q=' + encodeURIComponent(q)).then(function (response) {
                return response.json();
            }).then(function (rows) {
                scanList.replaceChildren();
                rows.forEach(function (row) {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'suggest-item';
                    button.textContent = row.label;
                    button.addEventListener('click', function () {
                        scanField.value = row.code;
                        scanList.hidden = true;
                        scanField.form.requestSubmit();
                    });
                    scanList.appendChild(button);
                });
                scanList.hidden = rows.length === 0;
            });
        }, 200);
    });
}
const buyerForm = document.getElementById('buyer-form');
if (buyerForm) {
    vendorPick(
        document.getElementById('buyer'),
        document.getElementById('buyer_id'),
        document.getElementById('buyer-suggest'),
        buyerForm.getAttribute('data-vendors'),
        function () {
            buyerForm.requestSubmit();
        }
    );
}
const instantForm = document.getElementById('instant-form');
if (instantForm) {
    const vendorNumber = document.getElementById('instant_vendor');
    const vendorId = document.getElementById('instant_vendor_id');
    const vendorError = document.getElementById('instant-vendor-error');
    const vendorHint = document.getElementById('instant-vendor-hint');
    const vendorsUrl = instantForm.getAttribute('data-vendors') || '';
    let vendorLookup = 0;
    function showVendorMiss() {
        if (vendorId) {
            vendorId.value = '';
        }
        if (vendorHint) {
            vendorHint.textContent = '';
            vendorHint.hidden = true;
        }
        if (vendorError) {
            vendorError.textContent = 'Choose a vendor.';
            vendorError.hidden = false;
        }
    }
    function sameVendor(left, right) {
        return String(left || '').trim().toLowerCase() === String(right || '').trim().toLowerCase();
    }
    function chooseVendor(rows, typed) {
        if (!Array.isArray(rows)) {
            return null;
        }
        const exact = rows.find(function (row) {
            return sameVendor(row.code, typed) || sameVendor(row.name, typed) || sameVendor(row.label, typed);
        });
        if (exact) {
            return exact;
        }
        const labeled = typed.match(/^#(\S+)\s+\u2014\s+/);
        if (labeled) {
            const byCode = rows.find(function (row) {
                return sameVendor(row.code, labeled[1]);
            });
            if (byCode) {
                return byCode;
            }
        }
        return rows.length === 1 ? rows[0] : null;
    }
    function acceptVendor(row, submitter) {
        if (!row || !row.id) {
            showVendorMiss();
            return;
        }
        if (vendorId) {
            vendorId.value = row.id;
        }
        if (vendorError) {
            vendorError.hidden = true;
        }
        if (vendorHint) {
            vendorHint.textContent = row.name || '';
            vendorHint.hidden = vendorHint.textContent === '';
        }
        if (submitter) {
            instantForm.requestSubmit(submitter);
        }
    }
    function lookupVendor(code, submitter) {
        const trimmed = code.trim();
        const ticket = ++vendorLookup;
        if (trimmed === '' || vendorsUrl === '') {
            showVendorMiss();
            if (vendorError && trimmed === '') {
                vendorError.hidden = true;
            }
            return;
        }
        const labeled = trimmed.match(/^#(\S+)\s+\u2014\s+/);
        const exactCode = labeled ? labeled[1] : trimmed;
        function stillTyping() {
            return ticket !== vendorLookup || !vendorNumber || vendorNumber.value.trim() !== trimmed;
        }
        fetch(vendorsUrl + '?code=' + encodeURIComponent(exactCode), { credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (rows) {
                if (stillTyping()) {
                    return null;
                }
                const row = Array.isArray(rows) ? rows[0] : null;
                if (row && row.id) {
                    acceptVendor(row, submitter);
                    return null;
                }
                return fetch(vendorsUrl + '?q=' + encodeURIComponent(trimmed), { credentials: 'same-origin' })
                    .then(function (response) { return response.json(); });
            })
            .then(function (rows) {
                if (rows === null || stillTyping()) {
                    return;
                }
                acceptVendor(chooseVendor(rows, trimmed), submitter);
            })
            .catch(function () {
                if (ticket !== vendorLookup) {
                    return;
                }
                if (vendorError) {
                    vendorError.textContent = 'Could not look up that vendor. Try again.';
                    vendorError.hidden = false;
                }
            });
    }
    vendorPick(
        vendorNumber,
        vendorId,
        document.getElementById('instant-vendor-suggest'),
        vendorsUrl
    );
    if (vendorNumber) {
        vendorNumber.addEventListener('input', function () {
            vendorLookup += 1;
            if (vendorId) {
                vendorId.value = '';
            }
            if (vendorError) {
                vendorError.hidden = true;
            }
            if (vendorHint) {
                vendorHint.textContent = '';
                vendorHint.hidden = true;
            }
        });
        vendorNumber.addEventListener('blur', function () {
            if (vendorId && vendorId.value !== '') {
                return;
            }
            lookupVendor(vendorNumber.value, null);
        });
    }
    function instantAmount(raw) {
        const cleaned = String(raw).replace(/[$,\s]/g, '');
        const match = /^(\d+)(?:\.(\d{1,2}))?$/.exec(cleaned);
        if (!match) {
            return 'enter an amount in dollars and cents';
        }
        if (match[1].length > 9) {
            return 'the amount is too large';
        }
        const cents = match[2] ? match[2].padEnd(2, '0') : '00';
        if ((Number(match[1]) * 100) + Number(cents) < 1) {
            return 'the amount must be greater than zero';
        }
        return '';
    }
    instantForm.addEventListener('submit', function (event) {
        const price = document.getElementById('instant_price');
        const banner = document.getElementById('instant-error');
        const kind = document.getElementById('instant_discount_kind');
        const discount = document.getElementById('instant_discount_value');
        let problem = '';
        let field = price;
        if (price && price.value.trim() === '') {
            problem = 'Enter a price.';
        } else if (price) {
            problem = instantAmount(price.value);
        }
        if (problem === '' && kind && discount && (kind.value === 'percent' || kind.value === 'flat')) {
            field = discount;
            if (discount.value.trim() === '') {
                problem = 'Enter a line discount.';
            } else if (kind.value === 'flat') {
                problem = instantAmount(discount.value);
            } else if (!/^(\d{1,3})(?:\.\d{1,2})?$/.test(discount.value.trim())) {
                problem = 'enter a percent from 0 to 100';
            }
        }
        if (problem !== '') {
            event.preventDefault();
            if (banner) {
                banner.hidden = false;
                banner.textContent = problem;
            }
            if (field) {
                field.setAttribute('aria-invalid', 'true');
                field.setAttribute('aria-describedby', 'instant-error');
            }
            if (typeof window.pcFlash === 'function') {
                const popup = document.getElementById('flash-dialog');
                if (popup && field) {
                    popup.addEventListener('close', function () {
                        field.focus();
                    }, { once: true });
                }
                window.pcFlash(problem, 'danger');
            } else if (field) {
                field.focus();
            }
            return;
        }
        if (banner) {
            banner.hidden = true;
            banner.textContent = '';
        }
        if (price) {
            price.removeAttribute('aria-invalid');
        }
        if (!vendorNumber || !vendorId || vendorId.value !== '') {
            return;
        }
        const code = vendorNumber.value.trim();
        if (code === '') {
            return;
        }
        event.preventDefault();
        lookupVendor(code, event.submitter || null);
    });
    const discType = document.getElementById('instant_discount_kind');
    const discVal = document.getElementById('instant_discount_value');
    if (discType && discVal) {
        function syncLineDiscount() {
            const kind = (discType.value || 'consignor_default').toLowerCase();
            const showValue = kind === 'percent' || kind === 'flat';
            discVal.disabled = !showValue;
            discVal.hidden = !showValue;
            discVal.required = showValue;
        }
        discType.addEventListener('change', syncLineDiscount);
        syncLineDiscount();
    }
    const brandLabel = document.getElementById('instant_brand');
    const brandId = document.getElementById('instant_brand_id');
    const brandSuggest = document.getElementById('instant-brand-suggest');
    const brandCreate = document.getElementById('instant-brand-create');
    const brandCreateName = document.getElementById('instant-brand-create-name');
    const brandCreateButton = document.getElementById('instant-brand-create-button');
    const brandError = document.getElementById('instant-brand-error');
    const brandsUrl = instantForm.getAttribute('data-brands') || '';
    const brandCreateUrl = instantForm.getAttribute('data-brand-create') || '';
    let brandTimer = null;
    function hideBrandSuggest() {
        if (!brandSuggest) {
            return;
        }
        brandSuggest.hidden = true;
        brandSuggest.replaceChildren();
    }
    function searchBrands(query) {
        fetch(brandsUrl + '?q=' + encodeURIComponent(query), { credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (rows) {
                if (!Array.isArray(rows) || !brandSuggest) {
                    return;
                }
                if (brandCreate) {
                    brandCreate.hidden = true;
                }
                if (rows.length === 0) {
                    hideBrandSuggest();
                    if (brandCreate && brandCreateName && query !== '') {
                        brandCreateName.textContent = query;
                        brandCreate.hidden = false;
                    }
                    return;
                }
                brandSuggest.replaceChildren();
                rows.forEach(function (row) {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'suggest-item';
                    button.textContent = row.name || '';
                    button.addEventListener('click', function () {
                        if (brandId) {
                            brandId.value = row.id || '';
                        }
                        if (brandLabel) {
                            brandLabel.value = row.name || '';
                        }
                        hideBrandSuggest();
                        if (brandCreate) {
                            brandCreate.hidden = true;
                        }
                    });
                    brandSuggest.appendChild(button);
                });
                brandSuggest.hidden = false;
            })
            .catch(hideBrandSuggest);
    }
    if (brandLabel) {
        brandLabel.addEventListener('input', function () {
            if (brandId) {
                brandId.value = '';
            }
            if (brandError) {
                brandError.hidden = true;
            }
            if (brandTimer) {
                clearTimeout(brandTimer);
            }
            const query = brandLabel.value.trim();
            if (query === '') {
                hideBrandSuggest();
                if (brandCreate) {
                    brandCreate.hidden = true;
                }
                return;
            }
            brandTimer = setTimeout(function () { searchBrands(query); }, 200);
        });
    }
    if (brandCreateButton) {
        brandCreateButton.addEventListener('click', function () {
            const name = brandLabel ? brandLabel.value.trim() : '';
            if (name === '' || brandCreateUrl === '') {
                return;
            }
            const token = instantForm.querySelector('input[name="csrf"]');
            const body = new URLSearchParams();
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
                        if (brandError) {
                            brandError.textContent = (result.payload && result.payload.error) || 'That brand was not added.';
                            brandError.hidden = false;
                        }
                        return;
                    }
                    if (brandId) {
                        brandId.value = result.payload.id;
                    }
                    if (brandLabel) {
                        brandLabel.value = result.payload.name || name;
                    }
                    if (brandCreate) {
                        brandCreate.hidden = true;
                    }
                    if (brandError) {
                        brandError.hidden = true;
                    }
                })
                .catch(function () {
                    if (brandError) {
                        brandError.textContent = 'That brand was not added.';
                        brandError.hidden = false;
                    }
                });
        });
    }
    <?php require __DIR__ . '/register-names.php'; ?>
}
document.querySelectorAll('.register-qty-input, .register-price').forEach(function (input) {
    input.addEventListener('change', function () {
        if (input.form) {
            input.form.requestSubmit();
        }
    });
});
document.querySelectorAll('[data-qty-delta]').forEach(function (button) {
    button.addEventListener('click', function () {
        const form = button.form;
        const input = form ? form.querySelector('.register-qty-input') : null;
        if (!form || !input) {
            return;
        }
        const next = parseInt(input.value, 10) + parseInt(button.getAttribute('data-qty-delta'), 10);
        if (next < 1) {
            return;
        }
        input.value = String(next);
        form.requestSubmit();
    });
});
<?php require __DIR__ . '/register-modals.php'; ?>
<?php require __DIR__ . '/pay-ui.php'; ?>
</script>
