<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var array{vendor_id: string, vendor_name: string, booth_id: string, booth: string, owed: int, rent: int, late: int, collect: string} $figure */
/** @var list<array{label: string, date: string, amount: int, journal_id: string}> $lines */
/** @var string $tenderCash */
/** @var string $tenderCard */
/** @var string $tenderChecks */
/** @var string $checkNumber */
/** @var bool $openRegister */

$booth = $figure['booth'] === '' ? '—' : $figure['booth'];
$owed = View::money($figure['owed']);
$checkOn = $tenderChecks !== '' && $tenderChecks !== '0' && $tenderChecks !== '0.00';
$waive = 0;
foreach ($lines as $line) {
    if ($line['journal_id'] !== '') {
        $waive++;
    }
}
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

<div class="collect-sheet" style="max-width:36rem">
<div class="card mt-6">
    <div class="card-body stack">
        <p class="slip-row"><span>Vendor</span><span><?= View::text($figure['vendor_name']) ?></span></p>
        <p class="slip-row"><span>Booth</span><span><?= View::text($booth) ?></span></p>
        <?php foreach ($lines as $line): ?>
            <?php
            $negative = $line['amount'] < 0;
            $amount = $negative ? '(' . View::money(-$line['amount']) . ')' : View::money($line['amount']);
            $canWaive = $line['journal_id'] !== '';
            ?>
            <?php if ($canWaive): ?>
                <form class="slip-row" method="post" action="/<?= View::text($slug) ?>/rent/waive" onsubmit="return confirm('Waive this late fee?')">
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <input type="hidden" name="vendor_id" value="<?= View::text($figure['vendor_id']) ?>">
                    <input type="hidden" name="booth_id" value="<?= View::text($figure['booth_id']) ?>">
                    <input type="hidden" name="journal_id" value="<?= View::text($line['journal_id']) ?>">
                    <span><?= View::text($line['label']) ?><?php if ($line['date'] !== ''): ?> <span class="muted"><?= View::date($line['date']) ?></span><?php endif; ?></span>
                    <span><?= View::text($amount) ?> <button class="btn" type="submit">Waive</button></span>
                </form>
            <?php else: ?>
                <p class="slip-row">
                    <span><?= View::text($line['label']) ?><?php if ($line['date'] !== ''): ?> <span class="muted"><?= View::date($line['date']) ?></span><?php endif; ?></span>
                    <span<?= $negative ? ' style="color:var(--danger);font-weight:700"' : '' ?>><?= View::text($amount) ?></span>
                </p>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($waive > 1): ?>
            <form method="post" action="/<?= View::text($slug) ?>/rent/waive" onsubmit="return confirm('Waive every late fee?')">
                <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                <input type="hidden" name="vendor_id" value="<?= View::text($figure['vendor_id']) ?>">
                <input type="hidden" name="booth_id" value="<?= View::text($figure['booth_id']) ?>">
                <input type="hidden" name="all" value="1">
                <p><button class="btn" type="submit">Waive all late fees</button></p>
            </form>
        <?php endif; ?>
        <p class="slip-row slip-total" id="rent-owed" data-cents="<?= View::text((string) $figure['owed']) ?>">
            <span>They owe</span><span style="font-size:1.75rem;font-weight:700"><?= View::text($owed) ?></span>
        </p>
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
            <input type="hidden" name="pay_with" value="split">
            <div class="field">
                <label for="tender-cash">Cash</label>
                <input class="input" id="tender-cash" name="tender_cash" inputmode="decimal" value="<?= View::text($tenderCash) ?>">
                <p class="muted text-sm">The bills they handed you.</p>
            </div>
            <div class="field">
                <label for="tender-card">Card</label>
                <input class="input" id="tender-card" name="tender_card" inputmode="decimal" value="<?= View::text($tenderCard) ?>">
            </div>
            <div class="field">
                <label for="tender-checks">Check</label>
                <input class="input" id="tender-checks" name="tender_checks" inputmode="decimal" value="<?= View::text($tenderChecks) ?>">
            </div>
            <p class="slip-row" id="rent-change" hidden></p>
            <div class="field" id="rent-check-box"<?= $checkOn ? '' : ' hidden' ?>>
                <label for="check-number">Check number</label>
                <input class="input" id="check-number" name="check_number" maxlength="40" value="<?= View::text($checkNumber) ?>" autocomplete="off"<?= $checkOn ? ' required' : '' ?>>
                <p class="muted text-sm">The number on their check.</p>
            </div>
            <p class="slip-row slip-total" id="rent-paying" hidden><span>They are paying</span><span id="rent-paying-amount"></span></p>
            <p><button class="btn btn-primary" type="submit">Collect</button></p>
        </div>
    </form>
    <script>
    (function () {
        var form = document.getElementById('rent-collect');
        var owed = document.getElementById('rent-owed');
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
            var bills = cents(cash.value);
            var cardCents = cents(card.value);
            var checkCents = cents(checks.value);
            var exact = cardCents + checkCents;
            checkBox.hidden = checkCents < 1;
            checkNumber.required = checkCents > 0;
            if (exact > due) {
                change.hidden = true;
                paying.hidden = false;
                payingAmount.textContent = money(exact) + '. That is ' + money(exact - due) + ' more than they owe.';
                return;
            }
            var applied = Math.min(bills, Math.max(0, due - exact));
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
            payingAmount.textContent = sum < due
                ? money(sum) + '. Still owed ' + money(due - sum) + '.'
                : money(sum);
        }
        form.addEventListener('input', paint);
        paint();
    }());
    </script>
<?php endif; ?>
</div>
