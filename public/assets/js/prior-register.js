(function () {
    const dialog = document.getElementById('prior-register');
    if (!dialog || typeof dialog.showModal !== 'function') {
        return;
    }
    dialog.addEventListener('cancel', function (event) {
        event.preventDefault();
    });
    dialog.showModal();
})();
