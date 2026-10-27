(function () {
    document.querySelectorAll('[data-print]').forEach(function (button) {
        button.addEventListener('click', function () {
            window.print();
        });
    });
    if (new URLSearchParams(window.location.search).get('print') === '1') {
        window.print();
    }
})();
