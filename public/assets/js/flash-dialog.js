(function () {
    const dialog = document.getElementById('flash-dialog');
    const panel = document.getElementById('flash-panel');
    const message = document.getElementById('flash-message');
    const text = message ? message.querySelector('p') : null;
    const ok = document.getElementById('flash-ok');
    let timer = 0;
    if (!dialog || !panel || !text || !ok || typeof dialog.showModal !== 'function') {
        return;
    }
    const tones = { success: 'success', warning: 'warning', danger: 'danger' };
    const labels = { success: 'notice', warning: 'warning', danger: 'error' };
    function shut() {
        if (timer !== 0) {
            window.clearTimeout(timer);
            timer = 0;
        }
        if (dialog.open) {
            dialog.close();
        }
    }
    dialog.addEventListener('cancel', function (event) {
        if (dialog.getAttribute('data-kind') !== 'success') {
            event.preventDefault();
        }
    });
    dialog.addEventListener('close', function () {
        if (timer !== 0) {
            window.clearTimeout(timer);
            timer = 0;
        }
    });
    ok.addEventListener('click', shut);
    window.pcFlash = function (copy, kind) {
        const resolved = kind === 'success' || kind === 'warning' ? kind : 'danger';
        if (!copy) {
            return;
        }
        shut();
        panel.className = 'alert alert-' + tones[resolved] + ' flash-panel';
        text.className = labels[resolved];
        text.textContent = copy;
        dialog.setAttribute('data-kind', resolved);
        dialog.showModal();
        ok.focus();
        if (resolved === 'success') {
            timer = window.setTimeout(shut, 2000);
        }
    };
    window.pcFlashClose = shut;
    if ((dialog.getAttribute('data-message') || '') !== '') {
        dialog.showModal();
        ok.focus();
        if (dialog.getAttribute('data-kind') === 'success') {
            timer = window.setTimeout(shut, 2000);
        }
    }
})();
