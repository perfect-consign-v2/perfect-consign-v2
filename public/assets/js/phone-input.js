/**
 * Live US phone mask for add/edit fields: (xxx) xxx-xxxx.
 * Binds inputs marked data-phone, and name=phone / customer_phone.
 */
(function () {
    'use strict';

    function digits(value) {
        return String(value || '').replace(/\D+/g, '');
    }

    function national(value) {
        let d = digits(value);
        if (d.length === 11 && d.charAt(0) === '1') {
            d = d.slice(1);
        }
        return d.slice(0, 10);
    }

    function format(raw) {
        const d = national(raw);
        if (d.length === 0) {
            return '';
        }
        if (d.length < 4) {
            return '(' + d;
        }
        if (d.length < 7) {
            return '(' + d.slice(0, 3) + ') ' + d.slice(3);
        }
        return '(' + d.slice(0, 3) + ') ' + d.slice(3, 6) + '-' + d.slice(6);
    }

    function caretAfter(formatted, digitCount) {
        if (digitCount <= 0) {
            return 0;
        }
        let seen = 0;
        for (let i = 0; i < formatted.length; i += 1) {
            if (/\d/.test(formatted.charAt(i))) {
                seen += 1;
                if (seen === digitCount) {
                    return i + 1;
                }
            }
        }
        return formatted.length;
    }

    function bind(input) {
        if (!(input instanceof HTMLInputElement)) {
            return;
        }
        if (input.getAttribute('data-phone-bound') === '1') {
            return;
        }
        input.setAttribute('data-phone-bound', '1');
        input.setAttribute('type', 'tel');
        input.setAttribute('inputmode', 'tel');
        if (!input.getAttribute('autocomplete')) {
            input.setAttribute('autocomplete', 'tel');
        }
        if (!input.getAttribute('maxlength')) {
            input.setAttribute('maxlength', '14');
        }
        const initial = format(input.value);
        if (input.value !== initial) {
            input.value = initial;
        }
        input.addEventListener('input', function () {
            const before = input.value;
            const pos = input.selectionStart;
            const digitsBefore = national(before.slice(0, pos === null ? before.length : pos)).length;
            const next = format(before);
            if (before === next) {
                return;
            }
            input.value = next;
            const nextPos = caretAfter(next, digitsBefore);
            input.setSelectionRange(nextPos, nextPos);
        });
        input.addEventListener('blur', function () {
            input.value = format(input.value);
        });
    }

    function scan(root) {
        const scope = root instanceof Element || root instanceof Document ? root : document;
        scope.querySelectorAll('input[data-phone], input[name="phone"], input[name="customer_phone"]').forEach(bind);
    }

    scan(document);
    const main = document.getElementById('main');
    if (main !== null) {
        const observer = new MutationObserver(function () {
            scan(main);
        });
        observer.observe(main, { childList: true, subtree: true });
    }
}());
