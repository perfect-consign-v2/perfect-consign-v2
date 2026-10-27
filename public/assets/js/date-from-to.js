var fromInput = document.getElementById('from');
    var toInput = document.getElementById('to');
    fromInput.addEventListener('change', function () {
        toInput.min = fromInput.value;
        if (toInput.value !== '' && toInput.value < fromInput.value) {
            toInput.value = fromInput.value;
        }
    });
