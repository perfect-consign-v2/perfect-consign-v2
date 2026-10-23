(function () {
    let generation = 0;

    function salePath() {
        const slug = window.location.pathname.split('/').filter(Boolean)[0] || '';
        return slug === '' ? '' : '/' + slug + '/pos';
    }

    function isSalePost(form, submitter) {
        if (!(form instanceof HTMLFormElement) || !form.closest('#main')) {
            return false;
        }
        const method = (submitter && submitter.formMethod ? submitter.formMethod : form.method).toLowerCase();
        if (method !== 'post') {
            return false;
        }
        const sale = salePath();
        if (sale === '') {
            return false;
        }
        let action;
        try {
            action = new URL(postUrl(form, submitter), window.location.href);
        } catch (error) {
            return false;
        }
        return action.origin === window.location.origin && (action.pathname === sale || action.pathname.startsWith(sale + '/'));
    }

    function postUrl(form, submitter) {
        if (submitter && submitter.hasAttribute('formaction')) {
            return submitter.formAction;
        }
        return form.action;
    }

    function formBody(form, submitter) {
        if (submitter instanceof HTMLElement && submitter.form === form) {
            return new FormData(form, submitter);
        }
        return new FormData(form);
    }

    function rememberSale(responseUrl) {
        const sale = salePath();
        const url = new URL(responseUrl, window.location.origin);
        const onSale = url.pathname === sale || url.pathname === sale + '/';
        const next = onSale ? url.pathname + url.search + url.hash : sale;
        history.replaceState(null, '', next);
    }

    function focusScan() {
        const scan = document.getElementById('scan');
        const open = document.querySelector('#main .modal:not([hidden])');
        if (!scan || scan.disabled || open) {
            return;
        }
        scan.focus();
    }

    function showFlash(parsed) {
        const flash = parsed.getElementById('flash-dialog');
        const message = flash ? flash.getAttribute('data-message') || '' : '';
        const kind = flash ? flash.getAttribute('data-kind') || '' : '';
        if (message !== '' && typeof window.pcFlash === 'function') {
            window.pcFlash(message, kind === 'success' || kind === 'warning' ? kind : 'danger');
        }
        if (message === '' || kind === 'success') {
            focusScan();
        }
    }

    function runScripts(root) {
        root.querySelectorAll('script').forEach(function (source) {
            const script = document.createElement('script');
            Array.from(source.attributes).forEach(function (attr) {
                script.setAttribute(attr.name, attr.value);
            });
            script.textContent = source.textContent;
            source.replaceWith(script);
        });
    }

    function apply(response, html) {
        const parsed = new DOMParser().parseFromString(html, 'text/html');
        const nextMain = parsed.querySelector('#main');
        const main = document.getElementById('main');
        if (!nextMain || !main) {
            if (response.redirected) {
                window.location.assign(response.url);
            }
            return;
        }
        if (!nextMain.querySelector('.register')) {
            if (response.redirected) {
                window.location.assign(response.url);
                return;
            }
            main.innerHTML = nextMain.innerHTML;
            if (parsed.title) {
                document.title = parsed.title;
            }
            runScripts(main);
            showFlash(parsed);
            return;
        }
        main.innerHTML = nextMain.innerHTML;
        if (parsed.title) {
            document.title = parsed.title;
        }
        rememberSale(response.url);
        if (typeof window.pcRegister === 'function') {
            window.pcRegister();
        }
        showFlash(parsed);
    }

    document.addEventListener('submit', function (event) {
        const form = event.target;
        const submitter = event.submitter || null;
        if (event.defaultPrevented || !isSalePost(form, submitter)) {
            return;
        }
        event.preventDefault();
        const ticket = ++generation;
        fetch(postUrl(form, submitter), {
            method: 'POST',
            body: formBody(form, submitter),
            credentials: 'same-origin',
            redirect: 'follow'
        }).then(function (response) {
            return response.text().then(function (html) {
                return { response: response, html: html };
            });
        }).then(function (result) {
            if (ticket !== generation) {
                return;
            }
            apply(result.response, result.html);
        }).catch(function () {
            if (ticket !== generation || typeof window.pcFlash !== 'function') {
                return;
            }
            window.pcFlash('The sale could not be saved.', 'danger');
        });
    });
}());
