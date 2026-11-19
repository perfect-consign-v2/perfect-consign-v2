(function () {
    'use strict';

    function bindNameLists() {
        document.querySelectorAll('[data-name-list]').forEach(bindNameList);
    }

    function bindNameList(box) {
        if (box.getAttribute('data-name-list-bound') === '1') {
            return;
        }
        const label = box.querySelector('input.input:not([type="hidden"])');
        const hidden = box.querySelector('input[type="hidden"]');
        const suggest = box.querySelector('.suggest');
        const create = box.querySelector('[data-create-row]');
        const createName = box.querySelector('[data-create-name]');
        const createButton = box.querySelector('[data-create-button]');
        const error = box.querySelector('.field-error');
        const searchUrl = box.getAttribute('data-search') || '';
        const createUrl = box.getAttribute('data-create') || '';
        const host = box.closest('form');
        let timer = null;
        let requestId = 0;
        if (!label || !hidden || !suggest || searchUrl === '') {
            return;
        }
        box.setAttribute('data-name-list-bound', '1');

        function hideSuggest() {
            suggest.hidden = true;
            suggest.replaceChildren();
        }

        function exact(rows, query) {
            const needle = query.trim().toLowerCase();
            return rows.some(function (row) {
                return String(row.name || '').trim().toLowerCase() === needle;
            });
        }

        function previewCreate(query) {
            box.removeAttribute('data-name-new');
            if (!create || !createName) {
                return;
            }
            if (query === '') {
                create.hidden = true;
                return;
            }
            createName.textContent = query;
            create.hidden = false;
        }

        function confirmCreate(query, matched) {
            if (query !== '' && label.value.trim() !== query) {
                return;
            }
            if (matched || query === '') {
                box.removeAttribute('data-name-new');
                if (create) {
                    create.hidden = true;
                }
                return;
            }
            box.setAttribute('data-name-new', '1');
            if (createName) {
                createName.textContent = query;
            }
            if (create) {
                create.hidden = false;
            }
        }

        function paint(rows, query) {
            if (!Array.isArray(rows)) {
                return;
            }
            const matched = exact(rows, query);
            confirmCreate(query, matched);
            if (rows.length === 0) {
                hideSuggest();
                return;
            }
            suggest.replaceChildren();
            rows.forEach(function (row) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'suggest-item';
                button.textContent = row.name || '';
                button.addEventListener('mousedown', function (event) {
                    event.preventDefault();
                    hidden.value = row.id || '';
                    label.value = row.name || '';
                    hideSuggest();
                    confirmCreate('', true);
                    if (error) {
                        error.hidden = true;
                    }
                });
                suggest.appendChild(button);
            });
            suggest.hidden = false;
        }

        function search(query) {
            const ticket = ++requestId;
            fetch(searchUrl + '?q=' + encodeURIComponent(query), { credentials: 'same-origin' })
                .then(function (response) { return response.json(); })
                .then(function (rows) {
                    if (ticket !== requestId) {
                        return;
                    }
                    paint(rows, query);
                })
                .catch(function () {
                    if (ticket !== requestId || label.value.trim() !== query) {
                        return;
                    }
                    hideSuggest();
                    if (hidden.value === '' && query !== '') {
                        box.setAttribute('data-name-new', '1');
                        if (createName) {
                            createName.textContent = query;
                        }
                        if (create) {
                            create.hidden = false;
                        }
                    }
                });
        }

        label.addEventListener('input', function () {
            hidden.value = '';
            if (error) {
                error.hidden = true;
            }
            if (timer) {
                clearTimeout(timer);
            }
            const query = label.value.trim();
            if (query === '') {
                requestId += 1;
                hideSuggest();
                previewCreate('');
                return;
            }
            previewCreate(query);
            timer = setTimeout(function () { search(query); }, 200);
        });

        if (createButton) {
            createButton.addEventListener('click', function () {
                const name = label.value.trim();
                if (name === '' || createUrl === '' || !host) {
                    return;
                }
                const token = host.querySelector('input[name="csrf"]');
                const body = new URLSearchParams();
                body.set('csrf', token ? token.value : '');
                body.set('name', name);
                fetch(createUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                })
                    .then(function (response) {
                        return response.json().then(function (payload) {
                            return { ok: response.ok, payload: payload };
                        });
                    })
                    .then(function (result) {
                        if (!result.ok || !result.payload || !result.payload.id) {
                            if (error) {
                                error.textContent = (result.payload && result.payload.error) || 'That name was not added.';
                                error.hidden = false;
                            }
                            return;
                        }
                        hidden.value = result.payload.id;
                        label.value = result.payload.name || name;
                        hideSuggest();
                        confirmCreate('', true);
                        if (error) {
                            error.hidden = true;
                        }
                    })
                    .catch(function () {
                        if (error) {
                            error.textContent = 'That name was not added.';
                            error.hidden = false;
                        }
                    });
            });
        }

        if (hidden.value === '' && label.value.trim() !== '') {
            search(label.value.trim());
        }
    }

    window.pcNameLists = bindNameLists;
    bindNameLists();
}());
