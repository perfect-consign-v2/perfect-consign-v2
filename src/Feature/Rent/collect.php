<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var array{vendor_id: string, vendor_name: string, booth_id: string, booth: string, owed: int, collect: string} $figure */
/** @var string $amount */
/** @var string $tenderCash */
/** @var string $tenderCard */
/** @var string $tenderChecks */
/** @var string $cashTendered */
/** @var string $checkNumber */
/** @var bool $openRegister */

$booth = $figure['booth'] === '' ? '—' : $figure['booth'];
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Collect rent</h1>
        <p class="muted"><?= View::text($figure['vendor_name']) ?></p>
    </div>
    <div class="page-actions">
        <a class="btn" href="/<?= View::text($slug) ?>/rent">Back to rent</a>
    </div>
</div>

<div class="card mt-6">
    <div class="card-body stack">
        <p class="slip-row"><span>Vendor</span><span><?= View::text($figure['vendor_name']) ?></span></p>
        <p class="slip-row"><span>Booth</span><span><?= View::text($booth) ?></span></p>
        <p class="slip-row"><span>Amount owed</span><span><?= View::text(View::money($figure['owed'])) ?></span></p>
    </div>
</div>

<?php if (!$openRegister): ?>
    <p class="mt-6"><a href="/<?= View::text($slug) ?>/registers">Open a register to collect</a></p>
<?php else: ?>
    <form class="card mt-6" id="rent-collect" method="post" action="/<?= View::text($slug) ?>/rent/payment">
        <div class="card-body stack">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <input type="hidden" name="vendor_id" value="<?= View::text($figure['vendor_id']) ?>">
            <input type="hidden" name="booth_id" value="<?= View::text($figure['booth_id']) ?>">
            <div class="field">
                <label for="amount">Amount received</label>
                <input class="input" id="amount" name="amount" inputmode="decimal" required value="<?= View::text($amount) ?>">
            </div>
            <div class="field">
                <label for="tender-cash">Cash</label>
                <input class="input" id="tender-cash" name="tender_cash" inputmode="decimal" value="<?= View::text($tenderCash) ?>">
                <p class="muted text-sm">Cash goes into the open drawer.</p>
            </div>
            <div class="field">
                <label for="tender-card">Card</label>
                <input class="input" id="tender-card" name="tender_card" inputmode="decimal" value="<?= View::text($tenderCard) ?>">
                <p class="muted text-sm">A card is the amount keyed.</p>
            </div>
            <div class="field">
                <label for="tender-checks">Check</label>
                <input class="input" id="tender-checks" name="tender_checks" inputmode="decimal" value="<?= View::text($tenderChecks) ?>">
                <p class="muted text-sm">A check is kept with its number.</p>
            </div>
            <div id="rent-cash-box" class="stack">
                <div class="field">
                    <label for="cash-tendered">Cash tendered</label>
                    <input class="input" id="cash-tendered" name="cash_tendered" inputmode="decimal" value="<?= View::text($cashTendered) ?>">
                </div>
                <p class="slip-row" id="rent-change" hidden></p>
            </div>
            <div class="field" id="rent-check-box" hidden>
                <label for="check-number">Check number</label>
                <input class="input" id="check-number" name="check_number" maxlength="40" value="<?= View::text($checkNumber) ?>" autocomplete="off">
            </div>
            <p id="rent-tender-gap" class="muted" hidden></p>
            <p><button class="btn btn-primary" type="submit">Collect</button></p>
        </div>
    </form>
    <script>
    (function () {
        var form = document.getElementById('rent-collect');
        if (!form) {
            return;
        }
        var amount = document.getElementById('amount');
        var cash = document.getElementById('tender-cash');
        var card = document.getElementById('tender-card');
        var checks = document.getElementById('tender-checks');
        var tendered = document.getElementById('cash-tendered');
        var cashBox = document.getElementById('rent-cash-box');
        var change = document.getElementById('rent-change');
        var checkBox = document.getElementById('rent-check-box');
        var gap = document.getElementById('rent-tender-gap');
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
        function paint() {
            var cashCents = cents(cash.value);
            var checkCents = cents(checks.value);
            var sum = cashCents + cents(card.value) + checkCents;
            var due = cents(amount.value);
            cashBox.hidden = cashCents < 1;
            checkBox.hidden = checkCents < 1;
            if (cashCents > 0) {
                var back = cents(tendered.value) - cashCents;
                change.hidden = false;
                change.textContent = back >= 0 ? 'Change ' + money(back) : 'Short ' + money(cashCents - cents(tendered.value));
            } else {
                change.hidden = true;
            }
            if (due > 0 && sum !== due) {
                gap.hidden = false;
                gap.textContent = 'Cash, card, and check must add up to the amount received.';
            } else {
                gap.hidden = true;
            }
        }
        form.addEventListener('input', paint);
        paint();
    }());
    </script>
<?php endif; ?>
