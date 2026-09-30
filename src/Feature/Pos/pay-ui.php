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
    const done = document.getElementById('pay-done');
    const hold = document.getElementById('pay-hold');
    const fields = form.querySelectorAll('[name^="tender_"]');
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
    function note(el, sum, settled) {
        if (sum > settled) {
            paint(el, 'Change due: ' + money(sum - settled), true, 'change');
            return;
        }
        if (sum > 0 && sum < settled) {
            paint(el, 'Amount due: ' + money(settled - sum), true, 'short');
            return;
        }
        paint(el, '', false, '');
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
        note(changeLine, sum, settled);
        note(shellChange, sum, settled);
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
        if (done) {
            done.disabled = checkMissing;
        }
        const covers = residual === 0 ? sum >= due : cashCents >= cashDue;
        if (process) {
            process.disabled = Number(form.getAttribute('data-lines')) < 1 || !covers || checkMissing;
        }
        if (hold) {
            let other = 0;
            fields.forEach(function (input) {
                if (input.name !== 'tender_cash' && input.name !== 'tender_card' && input.name !== 'tender_checks') {
                    other += cents(input.value);
                }
            });
            const buyer = form.getAttribute('data-buyer') || '';
            hold.disabled = buyer === '' || other > 0 || checkMissing || sum < 1 || covers || Number(form.getAttribute('data-lines')) < 1;
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
    form.querySelectorAll('[data-pay-full]').forEach(function (button) {
        button.addEventListener('click', function () {
            fill(button.getAttribute('data-pay-full'));
        });
    });
    fields.forEach(function (input) {
        input.addEventListener('input', settle);
    });
    if (checkNumber) {
        checkNumber.addEventListener('input', settle);
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
    document.querySelectorAll('[data-close-modal="enter-payment"]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (process && !process.disabled) {
                process.focus();
            }
        });
    });
    settle();
})();
