<?php

declare(strict_types=1);
?>
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
    function nickel(exact) {
        const inc = form.getAttribute('data-round') === '5' ? 5 : 1;
        if (inc <= 1 || exact <= 0) {
            return Math.max(0, exact);
        }
        const rem = exact % inc;
        if (rem === 0) {
            return exact;
        }
        if (rem * 2 >= inc) {
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
        const allCash = cashCents > 0 && nonCash === 0;
        const cashDue = allCash ? nickel(residual) : residual;
        const settled = due + (cashDue - residual);
        if (tendered) {
            tendered.textContent = money(sum);
        }
        if (shellTendered) {
            shellTendered.textContent = money(sum);
        }
        const adjustment = cashDue - residual;
        const showRound = adjustment !== 0;
        if (roundLine) {
            roundLine.hidden = !showRound;
        }
        if (roundAmount) {
            roundAmount.textContent = showRound ? (adjustment > 0 ? '+' : '−') + money(Math.abs(adjustment)) : '';
        }
        if (settledLine) {
            settledLine.hidden = !showRound;
        }
        if (settledAmount) {
            settledAmount.textContent = showRound ? money(due + adjustment) : '';
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
