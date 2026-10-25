(function () {
    const forms = document.querySelectorAll('form.ticket-command');
    forms.forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            send(form);
        });
    });

    function send(form) {
        const posting = document.getElementById('sale-posting');
        const refusal = document.getElementById('ticket-refusal');
        if (posting && form.id === 'pos-pay') {
            posting.textContent = 'This sale is not posted.';
        }
        if (refusal) {
            refusal.hidden = true;
            refusal.textContent = '';
        }
        const body = new FormData(form);
        fetch(form.action, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        }).then(function (response) {
            return response.json().then(function (payload) {
                return { ok: response.ok, payload: payload };
            });
        }).then(function (result) {
            const payload = result.payload || {};
            if (!result.ok || payload.error) {
                show(refusal, posting, payload.error || 'That could not be saved.');
                return;
            }
            if (payload.journal_no) {
                if (posting) {
                    posting.textContent = 'Receipt ' + payload.journal_no;
                }
                return;
            }
            if (payload.ticket && Array.isArray(payload.ticket.lines)) {
                const count = document.getElementById('sale-posting');
                if (count && form.action.indexOf('/pos/scan') !== -1) {
                    count.textContent = 'This sale is not posted.';
                }
            }
            if (payload.notice && posting) {
                posting.textContent = payload.notice;
            }
        }).catch(function () {
            if (form.id === 'pos-pay' && window.stallHoldSnap) {
                window.stallHoldSnap(form);
                return;
            }
            show(refusal, posting, 'That could not be saved.');
        });
    }

    function show(refusal, posting, message) {
        if (refusal) {
            refusal.hidden = false;
            refusal.textContent = message;
            return;
        }
        if (posting) {
            posting.textContent = message;
        }
    }
}());
