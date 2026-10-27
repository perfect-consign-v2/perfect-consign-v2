(function () {
        var form = document.getElementById('rent-collect');
        var owed = document.getElementById('rent-owed');
        var owedAmount = document.getElementById('rent-owed-amount');
        if (!form || !owed) {
            return;
        }
        var due = parseInt(owed.getAttribute('data-cents') || '0', 10);
        var cash = document.getElementById('tender-cash');
        var card = document.getElementById('tender-card');
        var checks = document.getElementById('tender-checks');
        var change = document.getElementById('rent-change');
        var checkBox = document.getElementById('rent-check-box');
        var checkNumber = document.getElementById('check-number');
        var paying = document.getElementById('rent-paying');
        var payingAmount = document.getElementById('rent-paying-amount');
        var noteBox = document.getElementById('waive-note-box');
        var all = document.getElementById('waive-all');
        var boxes = form.querySelectorAll('input[name="waive[]"]');
        function cents(raw) {
            var cleaned = String(raw || '').replace(/[$,\s]/g, '');
            if (cleaned === '') {
                return 0;
            }
            var value = Math.round(parseFloat(cleaned) * 100);
            return Number.isFinite(value) && value > 0 ? value : 0;
        }
        function money(value) {
            return '$' + Math.floor(value / 100) + '.' + String(value % 100).padStart(2, '0');
        }
        function waivedCents() {
            var sum = 0;
            boxes.forEach(function (box) {
                if (box.checked) {
                    sum += parseInt(box.getAttribute('data-cents') || '0', 10);
                }
            });
            return sum;
        }
        function syncAll() {
            if (!all) {
                return;
            }
            boxes.forEach(function (box) {
                box.checked = all.checked;
            });
        }
        function paint() {
            var left = Math.max(0, due - waivedCents());
            if (owedAmount) {
                owedAmount.textContent = money(left);
            }
            if (noteBox) {
                noteBox.hidden = !form.querySelector('input[name="waive[]"]:checked');
            }
            if (all && document.activeElement !== all) {
                var on = boxes.length > 0;
                boxes.forEach(function (box) {
                    if (!box.checked) {
                        on = false;
                    }
                });
                all.checked = on;
            }
            if (!cash || !card || !checks || !change || !checkBox || !checkNumber || !paying || !payingAmount) {
                return;
            }
            var bills = cents(cash.value);
            var cardCents = cents(card.value);
            var checkCents = cents(checks.value);
            var exact = cardCents + checkCents;
            checkBox.hidden = checkCents < 1;
            checkNumber.required = checkCents > 0;
            if (exact > left) {
                change.hidden = true;
                paying.hidden = false;
                payingAmount.textContent = money(exact) + '. That is ' + money(exact - left) + ' more than they owe.';
                return;
            }
            var applied = Math.min(bills, Math.max(0, left - exact));
            var sum = applied + exact;
            if (bills > applied) {
                change.hidden = false;
                change.textContent = 'Change ' + money(bills - applied);
            } else {
                change.hidden = true;
            }
            if (sum < 1) {
                paying.hidden = true;
                return;
            }
            paying.hidden = false;
            payingAmount.textContent = sum < left
                ? money(sum) + '. Still owed ' + money(left - sum) + '.'
                : money(sum);
        }
        if (all) {
            all.addEventListener('change', function () {
                syncAll();
                paint();
            });
        }
        form.addEventListener('input', function (event) {
            if (event.target && event.target.id === 'waive-all') {
                syncAll();
            }
            paint();
        });
        paint();
    }());
