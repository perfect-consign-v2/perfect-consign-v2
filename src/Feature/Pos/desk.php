<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Sell</h1>
        <p class="muted">The ticket comes back from the server. This page does not reload.</p>
    </div>
</div>
<form id="desk-scan">
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
    <label>Scan <input name="scan" autocomplete="off"></label>
    <button type="submit">Add</button>
</form>
<p id="desk-error"></p>
<ul id="desk-lines"></ul>
<p id="desk-due"></p>
<form id="desk-discount">
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
    <input type="hidden" name="discount_action" value="apply">
    <input type="hidden" name="discount_kind" value="amount">
    <label>Discount <input name="discount_value" inputmode="decimal" autocomplete="off"></label>
    <label>Reason <input name="discount_reason" autocomplete="off"></label>
    <button type="submit">Apply discount</button>
</form>
<form id="desk-pay">
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
    <label>Cash <input name="tender_cash" inputmode="decimal" autocomplete="off"></label>
    <button type="submit">Pay</button>
</form>
<p id="desk-notice"></p>
<script>
const scanForm = document.getElementById('desk-scan');
const lines = document.getElementById('desk-lines');
const due = document.getElementById('desk-due');
const errorLine = document.getElementById('desk-error');
const ticketUrl = '/<?= View::text($slug) ?>/pos/ticket.json';
const scanUrl = '/<?= View::text($slug) ?>/pos/scan';
const discountUrl = '/<?= View::text($slug) ?>/pos/discount';
const payUrl = '/<?= View::text($slug) ?>/pos/pay';
const discountForm = document.getElementById('desk-discount');
const payForm = document.getElementById('desk-pay');
const notice = document.getElementById('desk-notice');

function showTicket(ticket) {
    lines.replaceChildren();
    const rows = ticket && Array.isArray(ticket.lines) ? ticket.lines : [];
    rows.forEach(function (line) {
        const item = document.createElement('li');
        item.textContent = line.code + ' ' + line.description;
        lines.appendChild(item);
    });
    const minor = ticket && Number.isFinite(ticket.due) ? ticket.due : 0;
    due.textContent = (minor / 100).toFixed(2);
}

function readTicket() {
    fetch(ticketUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then(function (response) { return response.json(); })
        .then(function (payload) { showTicket(payload.ticket); });
}

function postForm(form, url) {
    const body = new URLSearchParams(new FormData(form));
    fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
    }).then(function (response) { return response.json(); })
        .then(function (payload) {
            errorLine.textContent = payload.error || '';
            notice.textContent = payload.notice || '';
            if (payload.ticket) {
                showTicket(payload.ticket);
            } else {
                readTicket();
            }
        });
}

if (discountForm) {
    discountForm.addEventListener('submit', function (event) {
        event.preventDefault();
        postForm(discountForm, discountUrl);
    });
}

if (payForm) {
    payForm.addEventListener('submit', function (event) {
        event.preventDefault();
        postForm(payForm, payUrl);
    });
}

if (scanForm) {
    scanForm.addEventListener('submit', function (event) {
        event.preventDefault();
        const body = new URLSearchParams(new FormData(scanForm));
        fetch(scanUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (response) { return response.json(); })
            .then(function (payload) {
                errorLine.textContent = payload.error || '';
                showTicket(payload.ticket);
                const scan = scanForm.querySelector('input[name="scan"]');
                if (scan) {
                    scan.value = '';
                }
            });
    });
}
readTicket();
</script>
