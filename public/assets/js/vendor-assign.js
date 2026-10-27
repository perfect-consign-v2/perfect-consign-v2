(function () {
                        var select = document.getElementById('assign_booth');
                        var rent = document.getElementById('assign_rent');
                        var start = document.getElementById('starts_on');
                        var badge = document.getElementById('assign_rent_badge');
                        var form = select ? select.form : null;
                        if (!select || !rent || !start || !form) {
                            return;
                        }
                        var today = form.getAttribute('data-today') || '';
                        var cents = function (text) {
                            var amount = String(text || '').replace(/[$,\s]/g, '');
                            var match = /^(\d+)(?:\.(\d{1,2}))?$/.exec(amount);
                            if (!match) {
                                return null;
                            }
                            var fraction = (match[2] || '').padEnd(2, '0');
                            return (parseInt(match[1], 10) * 100) + parseInt(fraction, 10);
                        };
                        var mark = function () {
                            if (!badge) {
                                return;
                            }
                            var option = select.options[select.selectedIndex];
                            var asking = cents(option ? option.getAttribute('data-rent') : '');
                            var typed = cents(rent.value);
                            badge.hidden = asking === null || typed === null || typed === asking;
                            badge.textContent = badge.hidden ? '' : 'Overridden';
                        };
                        var fill = function () {
                            var option = select.options[select.selectedIndex];
                            var available = option ? (option.getAttribute('data-available') || '') : '';
                            rent.value = option ? (option.getAttribute('data-rent') || '') : '';
                            if (available !== '') {
                                start.value = available;
                                start.min = available;
                            } else {
                                start.value = today;
                                start.removeAttribute('min');
                            }
                            mark();
                        };
                        select.addEventListener('change', fill);
                        rent.addEventListener('input', mark);
                        fill();
                    }());
