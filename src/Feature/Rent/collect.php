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
$showPay = $openRegister;
$showForm = $showPay || $waive > 0;
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
<?php if ($showForm): ?>
<form id="rent-collect" method="post" action="/<?= View::text($slug) ?>/rent/payment">
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
    <input type="hidden" name="vendor_id" value="<?= View::text($figure['vendor_id']) ?>">
    <input type="hidden" name="booth_id" value="<?= View::text($figure['booth_id']) ?>">
    <?php if ($showPay): ?>
        <input type="hidden" name="pay_with" value="split">
    <?php endif; ?>
<?php endif; ?>
<div class="card mt-6">
    <div class="card-body stack">
        <p class="slip-row"><span>Vendor</span><span><?= View::text($figure['vendor_name']) ?></span></p>
        <p class="slip-row"><span>Booth</span><span><?= View::text($booth) ?></span></p>
        <?php foreach ($lines as $line): ?>
            <?php
            $negative = $line['amount'] < 0;
            $amount = $negative ? '(' . View::money(-$line['amount']) . ')' : View::money($line['amount']);
            $canWaive = $line['journal_id'] !== '' && $line['amount'] > 0;
            $waiveLabel = 'Waive ' . lcfirst($line['label']);
            ?>
            <p class="slip-row">
                <span><?php if ($canWaive): ?><label><input type="checkbox" name="waive[]" value="<?= View::text($line['journal_id']) ?>" data-cents="<?= View::text((string) $line['amount']) ?>"><?= View::text($waiveLabel) ?><?php if ($line['date'] !== ''): ?> <span class="muted"><?= View::date($line['date']) ?></span><?php endif; ?></label><?php else: ?><?= View::text($line['label']) ?><?php if ($line['date'] !== ''): ?> <span class="muted"><?= View::date($line['date']) ?></span><?php endif; ?><?php endif; ?></span>
                <span<?= $negative ? ' style="color:var(--danger);font-weight:700"' : '' ?>><?= View::text($amount) ?></span>
            </p>
        <?php endforeach; ?>
        <?php if ($waive > 1): ?>
            <p><label><input type="checkbox" id="waive-all"> Waive all late fees</label></p>
        <?php endif; ?>
        <?php if ($waive > 0): ?>
            <div class="field" id="waive-note-box" hidden>
                <label for="waive-note">Waive note</label>
                <input class="input" id="waive-note" name="waive_note" maxlength="500">
                <label for="override_user">Second person</label>
                <input class="input" id="override_user" name="override_user" autocomplete="off">
                <label for="override_password">Their password</label>
                <input class="input" id="override_password" name="override_password" type="password" autocomplete="off">
                <p class="muted text-sm">Optional.</p>
            </div>
        <?php endif; ?>
        <p class="slip-row slip-total" id="rent-owed" data-cents="<?= View::text((string) $figure['owed']) ?>">
            <span>They owe</span><span id="rent-owed-amount" style="font-size:1.75rem;font-weight:700"><?= View::text($owed) ?></span>
        </p>
    </div>
</div>

<?php if (!$showPay): ?>
    <p class="mt-6"><a href="/<?= View::text($slug) ?>/registers">Open a register to collect</a></p>
    <?php if ($waive > 0): ?>
        <p><button class="btn" type="submit">Waive late fees</button></p>
    <?php endif; ?>
<?php else: ?>
    <div class="card mt-6">
        <div class="card-body stack">
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
            <div class="field" id="rent-check-box"<?= $checkOn ? '' : ' hidden' ?>>
                <label for="check-number">Check number</label>
                <input class="input" id="check-number" name="check_number" maxlength="40" value="<?= View::text($checkNumber) ?>" autocomplete="off"<?= $checkOn ? ' required' : '' ?>>
                <p class="muted text-sm">The number on their check.</p>
            </div>
            <p class="slip-row slip-total" id="rent-paying" hidden><span>They are paying</span><span id="rent-paying-amount"></span></p>
            <p class="slip-row" id="rent-change" hidden></p>
            <p><button class="btn btn-primary" type="submit">Collect</button></p>
        </div>
    </div>
<?php endif; ?>
<?php if ($showForm): ?>
</form>
    <script>
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
    </script>
<?php endif; ?>
</div>
