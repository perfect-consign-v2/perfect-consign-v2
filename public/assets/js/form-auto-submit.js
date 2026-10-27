(function () {
    document.querySelectorAll('[data-submit-on-change]').forEach(function (field) {
        field.addEventListener('change', function () {
            if (field.form) {
                field.form.submit();
            }
        });
    });
})();
