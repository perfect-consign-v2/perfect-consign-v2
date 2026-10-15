<?php

declare(strict_types=1);
?>
document.querySelectorAll('[data-open-modal]').forEach(function (button) {
    button.addEventListener('click', function () {
        const modal = document.getElementById(button.getAttribute('data-open-modal'));
        if (modal) {
            modal.hidden = false;
            if (modal.id === 'instant-add') {
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
            modal.hidden = true;
        }
    });
});
document.querySelectorAll('.modal').forEach(function (modal) {
    modal.addEventListener('click', function (event) {
        if (event.target === modal && !modal.hasAttribute('data-lock')) {
            modal.hidden = true;
        }
    });
});
