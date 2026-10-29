/**
 * Toggle visibility for inputs inside .password-field (sign-in, change password, overrides).
 */
(function () {
    'use strict';

    function bindField(wrap) {
        const input = wrap.querySelector('input');
        const btn = wrap.querySelector('.password-field__toggle');
        if (!input || !btn) {
            return;
        }
        btn.addEventListener('click', function () {
            const isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            wrap.classList.toggle('password-field--visible', isHidden);
            btn.setAttribute('aria-pressed', isHidden ? 'true' : 'false');
            btn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        });
    }

    function init() {
        const list = document.querySelectorAll('.password-field');
        for (let i = 0; i < list.length; i++) {
            bindField(list[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
