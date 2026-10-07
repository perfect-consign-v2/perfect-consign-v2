    instantForm.querySelectorAll('[data-name-list]').forEach(function (box) {
        const label = box.querySelector('input.input');
        const hidden = box.querySelector('input[type="hidden"]');
        const suggest = box.querySelector('.suggest');
        const create = box.querySelector('[data-create-row]');
        const createName = box.querySelector('[data-create-name]');
        const createButton = box.querySelector('[data-create-button]');
        const error = box.querySelector('.field-error');
        const searchUrl = box.getAttribute('data-search') || '';
        const createUrl = box.getAttribute('data-create') || '';
        let timer = null;
        if (!label || !hidden || !suggest) {
            return;
        }
        function hideSuggest() {
            suggest.hidden = true;
            suggest.replaceChildren();
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
                hideSuggest();
                if (create) {
                    create.hidden = true;
                }
                return;
            }
            timer = setTimeout(function () {
                fetch(searchUrl + '?q=' + encodeURIComponent(query), { credentials: 'same-origin' })
                    .then(function (response) { return response.json(); })
                    .then(function (rows) {
                        if (!Array.isArray(rows)) {
                            return;
                        }
                        if (create) {
                            create.hidden = true;
                        }
                        if (rows.length === 0) {
                            hideSuggest();
                            if (create && createName) {
                                createName.textContent = query;
                                create.hidden = false;
                            }
                            return;
                        }
                        suggest.replaceChildren();
                        rows.forEach(function (row) {
                            const button = document.createElement('button');
                            button.type = 'button';
                            button.className = 'suggest-item';
                            button.textContent = row.name || '';
                            button.addEventListener('click', function () {
                                hidden.value = row.id || '';
                                label.value = row.name || '';
                                hideSuggest();
                                if (create) {
                                    create.hidden = true;
                                }
                            });
                            suggest.appendChild(button);
                        });
                        suggest.hidden = false;
                    })
                    .catch(hideSuggest);
            }, 200);
        });
        if (createButton) {
            createButton.addEventListener('click', function () {
                const name = label.value.trim();
                if (name === '' || createUrl === '') {
                    return;
                }
                const token = instantForm.querySelector('input[name="csrf"]');
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
                        if (create) {
                            create.hidden = true;
                        }
                    });
            });
        }
    });
