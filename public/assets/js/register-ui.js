function pcRegister() {
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
        if (price && price.value.trim() !== '') {
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
    instantForm.querySelectorAll('[data-name-list]').forEach(function (box) {
        const label = box.querySelector('input.input');
        const hidden = box.querySelector('input[type="hidden"]');
        const suggest = box.querySelector('.suggest');
        const create = box.querySelector('[data-create-row]');
        const createName = box.querySelector('[data-create-name]');
        const createButton = box.querySelector('[data-create-button]');
        const error = box.querySelector('.field-error');
        const searchUrl = box.getAttribute('data-search') || '';
        const createUrl = box.getAttribute('data-create') || '';
        let timer = null;
        if (!label || !hidden || !suggest) {
            return;
        }
        function hideSuggest() {
            suggest.hidden = true;
            suggest.replaceChildren();
        }
        label.addEventListener('input', function () {
            hidden.value = '';
            if (error) {
                error.hidden = true;
            }
            if (timer) {
                clearTimeout(timer);
            }
            const query = label.value.trim();
            if (query === '') {
                hideSuggest();
                if (create) {
                    create.hidden = true;
                }
                return;
            }
            timer = setTimeout(function () {
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
                            if (create && createName) {
                                createName.textContent = query;
                                create.hidden = false;
                            }
                            return;
                        }
                        suggest.replaceChildren();
                        rows.forEach(function (row) {
                            const button = document.createElement('button');
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
            }, 200);
        });
        if (createButton) {
            createButton.addEventListener('click', function () {
                const name = label.value.trim();
                if (name === '' || createUrl === '') {
                    return;
                }
                const token = instantForm.querySelector('input[name="csrf"]');
                const body = new URLSearchParams();
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
                    });
            });
        }
    });
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
function blankGiftBalance() {
    const field = document.getElementById('gift');
    const result = document.querySelector('#gift-balance .gift-lookup');
    const url = new URL(window.location.href);
    if (field) {
        field.value = '';
    }
    if (result) {
        result.remove();
    }
    if (!url.searchParams.has('gift')) {
        return;
    }
    url.searchParams.delete('gift');
    const search = url.searchParams.toString();
    history.replaceState(null, '', url.pathname + (search === '' ? '' : '?' + search) + url.hash);
}
document.querySelectorAll('[data-open-modal]').forEach(function (button) {
    button.addEventListener('click', function () {
        const modal = document.getElementById(button.getAttribute('data-open-modal'));
        if (modal) {
            if (modal.id === 'gift-balance') {
                blankGiftBalance();
            }
            modal.hidden = false;
            if (modal.id === 'instant-add') {
                const draftNo = document.getElementById('instant_draft_no');
                const vendor = document.getElementById('instant_vendor');
                const vendorId = document.getElementById('instant_vendor_id');
                const description = document.getElementById('instant_description');
                const price = document.getElementById('instant_price');
                const brand = document.getElementById('instant_brand');
                const brandId = document.getElementById('instant_brand_id');
                const discountKind = document.getElementById('instant_discount_kind');
                const discountValue = document.getElementById('instant_discount_value');
                if (draftNo) {
                    draftNo.value = button.getAttribute('data-draft-no') || '';
                }
                if (vendor) {
                    vendor.value = button.getAttribute('data-draft-vendor-label') || vendor.value;
                }
                if (vendorId) {
                    vendorId.value = button.getAttribute('data-draft-vendor-id') || vendorId.value;
                }
                if (description && button.hasAttribute('data-draft-description')) {
                    description.value = button.getAttribute('data-draft-description') || '';
                }
                if (price && button.hasAttribute('data-draft-price')) {
                    price.value = button.getAttribute('data-draft-price') || '';
                }
                if (brand && button.hasAttribute('data-draft-brand-label')) {
                    brand.value = button.getAttribute('data-draft-brand-label') || '';
                }
                if (brandId && button.hasAttribute('data-draft-brand-id')) {
                    brandId.value = button.getAttribute('data-draft-brand-id') || '';
                }
                if (discountKind && button.hasAttribute('data-draft-discount-kind')) {
                    discountKind.value = button.getAttribute('data-draft-discount-kind') || 'consignor_default';
                }
                if (discountValue && button.hasAttribute('data-draft-discount-value')) {
                    discountValue.value = button.getAttribute('data-draft-discount-value') || '';
                }
                const consignor = document.getElementById('instant_vendor');
                if (consignor) {
                    consignor.focus();
                }
            }
            if (modal.id === 'enter-payment') {
                const cash = document.getElementById('pay-cash');
                if (cash) {
                    cash.focus();
                }
            }
            if (modal.id === 'gift-balance') {
                const number = document.getElementById('gift');
                if (number) {
                    number.focus();
                }
            }
            if (modal.id === 'customer') {
                const buyer = document.getElementById('customer_name');
                if (buyer) {
                    buyer.focus();
                }
            }
            if (modal.id === 'sale-completed') {
                const next = modal.querySelector('button[type="submit"]');
                if (next) {
                    next.focus();
                }
            }
        }
    });
});
document.querySelectorAll('#gift-sell input[name="gift_tender"]').forEach(function (input) {
    input.addEventListener('change', function () {
        const checkField = document.getElementById('gift-check-field');
        const checkError = document.getElementById('gift-check-error');
        const showCheck = input.value === 'checks' && input.checked;
        if (checkField) {
            checkField.hidden = !showCheck;
        }
        if (checkError && !showCheck) {
            checkError.hidden = true;
        }
    });
});
const giftForm = document.querySelector('#gift-sell form');
const giftCustom = document.getElementById('gift_custom');
const giftNumberField = document.getElementById('gift-number-field');
const giftNumber = document.getElementById('gift_number');
const giftNumberError = document.getElementById('gift-number-error');
const giftCheck = document.getElementById('gift_check');
const giftCheckError = document.getElementById('gift-check-error');
if (giftOpen && !giftOpen.hidden) {
    const focusGift = giftCheckError && !giftCheckError.hidden ? giftCheck : giftNumber;
    if (focusGift && focusGift.closest('[hidden]') === null) {
        focusGift.focus();
    }
}
if (giftCustom && giftNumberField) {
    giftCustom.addEventListener('change', function () {
        giftNumberField.hidden = !giftCustom.checked;
        if (giftCustom.checked) {
            if (giftNumber) {
                giftNumber.focus();
            }
            return;
        }
        if (giftNumber) {
            giftNumber.value = '';
        }
        if (giftNumberError) {
            giftNumberError.hidden = true;
        }
    });
}
if (giftForm) {
    giftForm.addEventListener('submit', function (event) {
        const selected = document.querySelector('#gift-sell input[name="gift_tender"]:checked');
        const checkMissing = !!(selected && selected.value === 'checks' && String(giftCheck && giftCheck.value || '').trim() === '');
        const numberMissing = !!(giftCustom && giftCustom.checked && String(giftNumber && giftNumber.value || '').trim() === '');
        if (giftCheckError) {
            giftCheckError.hidden = !checkMissing;
        }
        if (giftCheck) {
            if (checkMissing) {
                giftCheck.setAttribute('aria-invalid', 'true');
            } else {
                giftCheck.removeAttribute('aria-invalid');
            }
        }
        if (giftNumberError) {
            giftNumberError.hidden = !numberMissing;
        }
        if (!checkMissing && !numberMissing) {
            return;
        }
        event.preventDefault();
        const focus = checkMissing ? giftCheck : giftNumber;
        if (focus) {
            focus.focus();
        }
    });
}
document.querySelectorAll('[data-close-modal]').forEach(function (button) {
    button.addEventListener('click', function () {
        const modal = document.getElementById(button.getAttribute('data-close-modal'));
        if (modal) {
            if (modal.id === 'gift-balance') {
                blankGiftBalance();
            }
            modal.hidden = true;
        }
    });
});
document.querySelectorAll('.modal').forEach(function (modal) {
    modal.addEventListener('click', function (event) {
        if (event.target === modal && !modal.hasAttribute('data-lock')) {
            if (modal.id === 'gift-balance') {
                blankGiftBalance();
            }
            modal.hidden = true;
        }
    });
});
(function () {
    const form = document.getElementById('pos-pay');
    if (!form) {
        return;
    }
    const cash = document.getElementById('pay-cash');
    const tendered = document.getElementById('pay-tendered');
    const shellTendered = document.getElementById('shell-tendered');
    const roundLine = document.getElementById('pay-round');
    const roundAmount = document.getElementById('pay-round-amount');
    const settledLine = document.getElementById('pay-settled');
    const settledAmount = document.getElementById('pay-settled-amount');
    const summaryRound = document.getElementById('summary-cash-round');
    const summaryRoundAmount = document.getElementById('summary-cash-round-amount');
    const summaryDue = document.getElementById('summary-cash-due');
    const summaryDueAmount = document.getElementById('summary-cash-due-amount');
    const changeLine = document.getElementById('pay-change');
    const shellChange = document.getElementById('shell-change');
    const process = document.querySelector('button.register-complete[form="pos-pay"]');
    const sales = document.getElementById('pay-vendor');
    const salesMax = document.getElementById('pay-sales-max');
    const check = document.getElementById('pay-check');
    const checkNumber = document.getElementById('pay-check-number');
    const checkError = document.getElementById('pay-check-error');
    const giftAmount = document.getElementById('pay-gift');
    const giftNumber = document.getElementById('pay-gift-number');
    const giftError = document.getElementById('pay-gift-error');
    const giftBalance = document.getElementById('pay-gift-balance');
    const giftLookup = document.getElementById('pay-gift-lookup');
    const giftOver = form.getAttribute('data-gift-over') || '';
    let giftCap = null;
    const finish = document.getElementById('pay-finish');
    const hold = document.getElementById('pay-hold');
    const payError = document.getElementById('pay-error');
    const fields = form.querySelectorAll('[name^="tender_"]');
    const amountMessages = [
        'enter an amount in dollars and cents',
        'the amount is too large',
        'the amount must be greater than zero'
    ];
    function money(cents) {
        const n = Math.max(0, cents);
        return '$' + Math.floor(n / 100) + '.' + String(n % 100).padStart(2, '0');
    }
    function cents(raw) {
        const cleaned = String(raw || '').replace(/[$,\s]/g, '');
        if (cleaned === '') {
            return 0;
        }
        const value = Math.round(parseFloat(cleaned) * 100);
        return Number.isFinite(value) && value > 0 ? value : 0;
    }
    function amountProblem(raw) {
        const cleaned = String(raw || '').replace(/[$,\s]/g, '');
        if (cleaned === '' || cleaned === '0' || cleaned === '0.00') {
            return '';
        }
        const match = /^(\d+)(?:\.(\d{1,2}))?$/.exec(cleaned);
        if (!match) {
            return amountMessages[0];
        }
        if (match[1].length > 9) {
            return amountMessages[1];
        }
        const minor = (Number(match[1]) * 100) + Number((match[2] || '').padEnd(2, '0'));
        if (!Number.isFinite(minor) || minor < 1) {
            return amountMessages[2];
        }
        return '';
    }
    function amountError() {
        let problem = '';
        fields.forEach(function (input) {
            const found = amountProblem(input.value);
            if (found !== '') {
                problem = found;
                input.setAttribute('aria-invalid', 'true');
                input.setAttribute('aria-describedby', 'pay-error');
                return;
            }
            if (input.getAttribute('aria-describedby') === 'pay-error') {
                input.removeAttribute('aria-invalid');
                input.removeAttribute('aria-describedby');
            }
        });
        return problem;
    }
    function cashStep() {
        const step = parseInt(form.getAttribute('data-round') || '0', 10);
        return Number.isFinite(step) && step >= 1 ? step : 0;
    }
    function roundCash(exact) {
        const inc = cashStep();
        if (inc < 1 || exact <= 0) {
            return Math.max(0, exact);
        }
        const rem = exact % inc;
        if (rem === 0) {
            return exact;
        }
        if (rem * 2 > inc) {
            return exact + (inc - rem);
        }
        return exact - rem;
    }
    function paint(el, text, show, kind) {
        if (!el) {
            return;
        }
        el.hidden = !show;
        el.textContent = show ? text : '';
        el.dataset.kind = show ? kind : '';
    }
    function note(el, sum, settled, cashCents) {
        const handed = cashCents > 0 ? 'Cash handed: ' + money(cashCents) + '. ' : '';
        if (sum > settled) {
            paint(el, handed + 'Change due: ' + money(sum - settled), true, 'change');
            return;
        }
        if (sum > 0 && sum < settled) {
            paint(el, handed + 'Amount due: ' + money(settled - sum), true, 'short');
            return;
        }
        paint(el, handed, handed !== '', 'change');
    }
    function salesCap() {
        const raw = parseInt(form.getAttribute('data-sales') || '0', 10);
        return Number.isFinite(raw) && raw > 0 ? raw : 0;
    }
    function clampSales() {
        if (!sales || sales.disabled) {
            return;
        }
        const cap = salesCap();
        if (cents(sales.value) > cap) {
            sales.value = (cap / 100).toFixed(2);
        }
    }
    function settle() {
        clampSales();
        let sum = 0;
        fields.forEach(function (input) {
            sum += cents(input.value);
        });
        const cashCents = cents(cash && cash.value);
        const due = cents(form.getAttribute('data-due'));
        const nonCash = Math.max(0, sum - cashCents);
        const residual = Math.max(0, due - nonCash);
        const cashPriced = cashStep() >= 1 && nonCash === 0;
        const cashDue = cashPriced ? roundCash(residual) : residual;
        const settled = due + (cashDue - residual);
        if (tendered) {
            tendered.textContent = money(sum);
        }
        if (shellTendered) {
            shellTendered.textContent = money(sum);
        }
        const adjustment = cashDue - residual;
        const showRound = form.getAttribute('data-cash-quote') === '1' && nonCash === 0 && adjustment !== 0 && residual > 0;
        const roundText = showRound ? (adjustment > 0 ? '+' : '-') + money(Math.abs(adjustment)) : '';
        const dueText = showRound ? money(due + adjustment) : '';
        if (roundLine) {
            roundLine.hidden = !showRound;
        }
        if (roundAmount) {
            roundAmount.textContent = roundText;
        }
        if (settledLine) {
            settledLine.hidden = !showRound;
        }
        if (settledAmount) {
            settledAmount.textContent = dueText;
        }
        if (summaryRound) {
            summaryRound.hidden = !showRound;
        }
        if (summaryRoundAmount) {
            summaryRoundAmount.textContent = roundText;
        }
        if (summaryDue) {
            summaryDue.hidden = !showRound;
        }
        if (summaryDueAmount) {
            summaryDueAmount.textContent = dueText;
        }
        note(changeLine, sum, settled, cashCents);
        note(shellChange, sum, settled, cashCents);
        const checkMissing = cents(check && check.value) > 0 && String(checkNumber && checkNumber.value || '').trim() === '';
        if (checkError) {
            checkError.hidden = !checkMissing;
        }
        if (checkNumber) {
            checkNumber.required = checkMissing;
            if (checkMissing) {
                checkNumber.setAttribute('aria-invalid', 'true');
            } else {
                checkNumber.removeAttribute('aria-invalid');
            }
        }
        const giftTooMuch = giftCap !== null && cents(giftAmount && giftAmount.value) > giftCap;
        if (giftError && giftCap !== null) {
            if (giftTooMuch) {
                giftError.hidden = false;
                giftError.textContent = giftOver;
            } else if (giftError.textContent === giftOver) {
                giftError.hidden = true;
                giftError.textContent = '';
            }
        }
        const typedAmount = amountError();
        if (payError && (typedAmount !== '' || amountMessages.indexOf(payError.textContent) !== -1)) {
            const previous = payError.textContent;
            payError.hidden = typedAmount === '';
            payError.textContent = typedAmount;
            if (typedAmount !== '' && typedAmount !== previous && typeof window.pcFlash === 'function') {
                window.pcFlash(typedAmount, 'danger');
            } else if (typedAmount === '' && amountMessages.indexOf(previous) !== -1 && typeof window.pcFlashClose === 'function') {
                window.pcFlashClose();
            }
        }
        const giftBlocked = giftError ? !giftError.hidden : false;
        const covers = residual === 0 ? sum >= due : cashCents >= cashDue;
        const short = Number(form.getAttribute('data-lines')) < 1 || !covers || checkMissing || giftBlocked || typedAmount !== '';
        if (process) {
            process.disabled = short;
        }
        if (finish) {
            finish.disabled = short;
        }
        if (hold) {
            let other = 0;
            fields.forEach(function (input) {
                if (input.name !== 'tender_cash' && input.name !== 'tender_card' && input.name !== 'tender_checks') {
                    other += cents(input.value);
                }
            });
            const shopper = form.getAttribute('data-shopper') || '';
            hold.disabled = shopper === '' || other > 0 || checkMissing || typedAmount !== '' || sum < 1 || covers || Number(form.getAttribute('data-lines')) < 1;
        }
    }
    function fill(role) {
        const due = form.getAttribute('data-due') || '';
        const cashAmount = form.getAttribute('data-cash') || due;
        fields.forEach(function (input) {
            if (input.disabled) {
                return;
            }
            input.value = input.name === 'tender_' + role ? (role === 'cash' ? cashAmount : due) : '';
        });
        form.querySelectorAll('[name="check_number"], [name$="_number"]').forEach(function (input) {
            input.value = '';
        });
        settle();
        const focus = document.getElementById(role === 'checks' ? 'pay-check-number' : (role === 'cash' ? 'pay-cash' : (role === 'card' ? 'pay-card' : 'pay-check')));
        if (focus) {
            focus.focus();
        }
    }
    form.addEventListener('submit', function (event) {
        if (amountError() !== '') {
            event.preventDefault();
            settle();
        }
    });
    form.querySelectorAll('[data-pay-full]').forEach(function (button) {
        button.addEventListener('click', function () {
            fill(button.getAttribute('data-pay-full'));
        });
    });
    fields.forEach(function (input) {
        input.addEventListener('input', function () {
            if (input === giftAmount && giftCap === null && giftError && giftError.textContent === giftOver) {
                giftError.hidden = true;
                giftError.textContent = '';
            }
            settle();
        });
    });
    if (checkNumber) {
        checkNumber.addEventListener('input', settle);
    }
    let giftToken = 0;
    function showGift(error, balance) {
        if (giftError) {
            giftError.hidden = error === '';
            giftError.textContent = error;
        }
        if (giftBalance) {
            giftBalance.hidden = balance === '';
            giftBalance.textContent = balance;
        }
        if (giftNumber) {
            if (error === '') {
                giftNumber.removeAttribute('aria-invalid');
            } else {
                giftNumber.setAttribute('aria-invalid', 'true');
            }
        }
        settle();
    }
    function lookupGift() {
        const number = String(giftNumber && giftNumber.value || '').trim();
        if (number === '') {
            giftCap = null;
            showGift('', '');
            return;
        }
        const token = ++giftToken;
        const url = (form.getAttribute('data-gift-lookup') || '') + '?number=' + encodeURIComponent(number);
        fetch(url, { headers: { Accept: 'application/json' } })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (token !== giftToken) {
                    return;
                }
                if (!data || data.ok !== true) {
                    giftCap = null;
                    showGift(data && data.error ? String(data.error) : 'that gift certificate number is not on file', '');
                    return;
                }
                giftCap = Number.isFinite(Number(data.cents)) ? Number(data.cents) : null;
                showGift('', 'Balance left ' + String(data.balance || ''));
            })
            .catch(function () {
                if (token !== giftToken) {
                    return;
                }
                giftCap = null;
                showGift('that gift certificate number is not on file', '');
            });
    }
    if (giftNumber) {
        giftNumber.addEventListener('input', function () {
            giftToken += 1;
            giftCap = null;
            showGift('', '');
        });
        giftNumber.addEventListener('change', lookupGift);
        giftNumber.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter') {
                return;
            }
            event.preventDefault();
            lookupGift();
        });
    }
    if (giftLookup) {
        giftLookup.addEventListener('click', lookupGift);
    }
    if (salesMax) {
        salesMax.addEventListener('click', function () {
            if (!sales || sales.disabled) {
                return;
            }
            let others = 0;
            fields.forEach(function (input) {
                if (input !== sales) {
                    others += cents(input.value);
                }
            });
            const room = Math.max(0, cents(form.getAttribute('data-due')) - others);
            const use = Math.min(salesCap(), room);
            sales.value = (use / 100).toFixed(2);
            settle();
        });
    }
    settle();
})();
}
pcRegister();