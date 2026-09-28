<?php

declare(strict_types=1);

use PerfectConsign\Feature\Pos\TicketAdjustment;
use PerfectConsign\Feature\Pos\TicketQuote;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Scalar\RowValue;

/** @var string $slug */
/** @var string $csrf */
/** @var array{
 *     ticket_id: string,
 *     lines: list<array{line_no: int, item_id: string, code: string, description: string, vendor: string, vendor_id: string, price: int, commission: int, tax: int, discount: int}>,
 *     merchandise: int,
 *     discount: int,
 *     discount_reason: string,
 *     tax: int,
 *     tax_bps: int,
 *     waiver: string,
 *     due: int,
 *     cash_round: int,
 *     cash_due: int,
 *     rounding: string,
 *     parked: list<array{id: string, pieces: int}>
 * } $cart
 */
$lines = $cart['lines'];
$parked = $cart['parked'];
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">POS</h1>
        <p class="muted">Scan a barcode or a tag. The cart is this shift's open ticket.</p>
        <?php if (($priorCloseStarted ?? false) === true && ($leftOpen[0]['id'] ?? '') !== ''): ?>
            <p><a class="text-link" href="/<?= View::text($slug) ?>/registers?close=<?= View::text((string) $leftOpen[0]['id']) ?>">Back to close the register</a></p>
        <?php endif; ?>
    </div>
</div>

<div class="card mt-6">
    <div class="card-body">
        <form method="post" action="/<?= View::text($slug) ?>/pos/scan" class="row gap-2">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <div class="field" style="flex:1">
                <label for="scan">Scan</label>
                <input class="input" id="scan" name="scan" autofocus autocomplete="off">
            </div>
            <button class="btn btn-primary" type="submit">Add</button>
        </form>
    </div>
</div>

<div class="card mt-6">
    <div class="card-head"><div class="h3">Cart</div></div>
    <div class="card-body">
        <table>
            <thead>
                <tr><th>Piece</th><th>Vendor</th><th>Sold</th><th>Store take</th><th>Tax</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($lines as $line): ?>
                <tr>
                    <td><?= View::text($line['code']) ?> <?= View::text($line['description']) ?></td>
                    <td><?= View::text($line['vendor']) ?></td>
                    <td><?= View::text(View::money(RowValue::integer($line['price']))) ?></td>
                    <td><?= View::text(View::money(RowValue::integer($line['commission']))) ?></td>
                    <td><?= View::text(View::money(RowValue::integer($line['tax']))) ?></td>
                    <td>
                        <form method="post" action="/<?= View::text($slug) ?>/pos/price" class="row gap-2">
                            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                            <input type="hidden" name="line_no" value="<?= View::text($line['line_no']) ?>">
                            <input class="input" name="price" inputmode="decimal" value="<?= View::text(View::money(RowValue::integer($line['price']))) ?>" aria-label="Sold price">
                            <button class="btn" type="submit">Price</button>
                        </form>
                        <form method="post" action="/<?= View::text($slug) ?>/pos/remove">
                            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                            <input type="hidden" name="line_no" value="<?= View::text($line['line_no']) ?>">
                            <button class="btn btn-ghost" type="submit">Remove</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p>Subtotal <span id="merchandise"><?= View::text(View::money(RowValue::integer($cart['merchandise']))) ?></span></p>
        <?php if ($cart['discount'] > 0): ?>
            <p>Discount <span id="discount"><?= View::text(View::money(RowValue::integer($cart['discount']))) ?></span> <?= View::text($cart['discount_reason']) ?></p>
        <?php endif; ?>
        <p>Tax
            <?php if ($cart['waiver'] !== ''): ?>
                <span class="badge" id="tax-waived">Tax waived</span> <?= View::text($cart['waiver']) ?>
            <?php else: ?>
                <span id="tax"><?= View::text(View::money(RowValue::integer($cart['tax']))) ?></span>
                <?= View::text(TicketQuote::percentLabel(RowValue::integer($cart['tax_bps']))) ?>
            <?php endif; ?>
        </p>
        <p>Amount due <span id="amount-due"><?= View::text(View::money(RowValue::integer($cart['due']))) ?></span></p>
        <?php if ($cart['rounding'] === '1' && $lines !== []): ?>
            <p>Cash round <span id="cash-round"><?= View::text(View::money(RowValue::integer($cart['cash_round']))) ?></span></p>
            <p>Cash due <span id="cash-due"><?= View::text(View::money(RowValue::integer($cart['cash_due']))) ?></span></p>
        <?php endif; ?>
        <?php if ($cart['ticket_id'] !== ''): ?>
            <div class="row gap-2 mt-4">
                <button class="btn" type="button" data-open-modal="discount-modal">Discount</button>
                <button class="btn" type="button" data-open-modal="tax-free-modal">Tax-free</button>
                <form method="post" action="/<?= View::text($slug) ?>/pos/park">
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <button class="btn" type="submit">Park</button>
                </form>
                <form method="post" action="/<?= View::text($slug) ?>/pos/discard">
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <button class="btn btn-ghost" type="submit">Discard</button>
                </form>
            </div>
            <?php if ($lines !== []): ?>
                <button class="btn btn-primary mt-4" type="button" data-open-modal="charge-modal" style="width:100%">Charge <?= View::text(View::money(RowValue::integer($cart['due']))) ?></button>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($parked !== []): ?>
<div class="card mt-6">
    <div class="card-head"><div class="h3">Parked</div></div>
    <div class="card-body stack">
        <?php foreach ($parked as $ticket): ?>
            <form method="post" action="/<?= View::text($slug) ?>/pos/recall" class="row between">
                <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                <input type="hidden" name="ticket_id" value="<?= View::text($ticket['id']) ?>">
                <p><?= View::text($ticket['pieces']) ?> pieces</p>
                <button class="btn" type="submit">Recall</button>
            </form>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($cart['ticket_id'] !== ''): ?>
<div id="discount-modal" class="modal" role="dialog" aria-modal="true" aria-labelledby="discount-title" hidden>
    <form class="modal-panel card" method="post" action="/<?= View::text($slug) ?>/pos/discount">
        <div class="card-head"><div class="h3" id="discount-title">Discount</div></div>
        <div class="card-body stack">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <div class="row gap-2">
                <label><input type="radio" name="discount_kind" value="percent" checked> Percent</label>
                <label><input type="radio" name="discount_kind" value="amount"> Amount</label>
            </div>
            <div class="field">
                <label for="discount_value">Value</label>
                <input class="input" id="discount_value" name="discount_value" inputmode="decimal">
            </div>
            <div class="field">
                <label for="discount_reason">Reason</label>
                <select class="input" id="discount_reason" name="discount_reason">
                    <?php foreach (TicketAdjustment::DISCOUNT_REASONS as $reason): ?>
                        <option value="<?= View::text($reason) ?>"><?= View::text($reason) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="row gap-2">
                <button class="btn btn-ghost" type="submit" name="discount_action" value="remove">Remove discount</button>
                <button class="btn btn-primary" type="submit" name="discount_action" value="apply">Apply</button>
            </div>
            <button class="btn" type="button" data-close-modal="discount-modal">Close</button>
        </div>
    </form>
</div>
<div id="tax-free-modal" class="modal" role="dialog" aria-modal="true" aria-labelledby="tax-free-title" hidden>
    <form class="modal-panel card" method="post" action="/<?= View::text($slug) ?>/pos/tax-free">
        <div class="card-head"><div class="h3" id="tax-free-title">Tax-free</div></div>
        <div class="card-body stack">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <p>This waives tax on this ticket only. A tax-exempt vendor is already zero.</p>
            <div class="field">
                <label for="waiver_reason">Reason</label>
                <select class="input" id="waiver_reason" name="waiver_reason">
                    <?php foreach (TicketAdjustment::WAIVER_REASONS as $reason): ?>
                        <option value="<?= View::text($reason) ?>"><?= View::text($reason) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="row gap-2">
                <button class="btn btn-ghost" type="submit" name="waiver_action" value="restore">Restore tax</button>
                <button class="btn btn-primary" type="submit" name="waiver_action" value="waive">Waive tax</button>
            </div>
            <button class="btn" type="button" data-close-modal="tax-free-modal">Close</button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if ($lines !== []): ?>
<div id="charge-modal" class="modal" role="dialog" aria-modal="true" aria-labelledby="charge-title" hidden>
    <form id="charge-form" class="modal-panel card" method="post" action="/<?= View::text($slug) ?>/pos/pay" data-due="<?= View::text(View::money(RowValue::integer($cart['due']))) ?>">
        <div class="card-head"><div class="h3" id="charge-title">Charge <?= View::text(View::money(RowValue::integer($cart['due']))) ?></div></div>
        <div class="card-body stack">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <input type="hidden" name="tender_cash" value="">
            <input type="hidden" name="tender_card" value="">
            <input type="hidden" name="tender_checks" value="">
            <input type="hidden" name="tender_gift_certificate" value="">
            <input type="hidden" name="tender_gift_card" value="">
            <input type="hidden" name="tender_store_credit" value="">
            <p>Amount due <?= View::text(View::money(RowValue::integer($cart['due']))) ?></p>
            <div class="stack">
                <label><input type="radio" name="charge_tender" value="cash" checked> Cash</label>
                <label><input type="radio" name="charge_tender" value="card"> Card</label>
                <label><input type="radio" name="charge_tender" value="checks"> Check</label>
                <label><input type="radio" name="charge_tender" value="gift_certificate"> Gift certificate</label>
                <label><input type="radio" name="charge_tender" value="gift_card"> Gift card</label>
                <label><input type="radio" name="charge_tender" value="store_credit"> Store credit</label>
            </div>
            <div class="field">
                <label for="charge-amount">Amount</label>
                <input class="input" id="charge-amount" inputmode="decimal">
            </div>
            <div class="field" data-tender="checks" hidden>
                <label for="check_number">Check number</label>
                <input class="input" id="check_number" name="check_number">
            </div>
            <div class="field" data-tender="gift_certificate" hidden>
                <label for="gift_certificate_number">Gift certificate number</label>
                <input class="input" id="gift_certificate_number" name="gift_certificate_number">
            </div>
            <div class="field" data-tender="gift_card" hidden>
                <label for="gift_card_number">Gift card number</label>
                <input class="input" id="gift_card_number" name="gift_card_number">
            </div>
            <div class="field" data-tender="store_credit" hidden>
                <label for="store_credit_number">Store credit number</label>
                <input class="input" id="store_credit_number" name="store_credit_number">
            </div>
            <div class="field" data-tender="cash">
                <label for="cash_received">Cash received</label>
                <input class="input" id="cash_received" name="cash_received" inputmode="decimal">
            </div>
            <button class="btn btn-primary" type="submit">Complete</button>
            <button class="btn" type="button" data-close-modal="charge-modal">Close</button>
        </div>
    </form>
</div>
<?php endif; ?>

<script>
const scanField = document.getElementById('scan');
if (scanField) {
    scanField.focus();
}
const chargeForm = document.getElementById('charge-form');
if (chargeForm) {
    const amount = document.getElementById('charge-amount');
    const due = chargeForm.getAttribute('data-due');
    if (amount && due) {
        amount.value = due;
    }
    chargeForm.querySelectorAll('input[name="charge_tender"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            chargeForm.querySelectorAll('[data-tender]').forEach(function (block) {
                block.hidden = block.getAttribute('data-tender') !== radio.value;
            });
        });
    });
    chargeForm.addEventListener('submit', function () {
        const picked = chargeForm.querySelector('input[name="charge_tender"]:checked');
        const role = picked ? picked.value : '';
        ['cash', 'card', 'checks', 'gift_certificate', 'gift_card', 'store_credit'].forEach(function (name) {
            const field = chargeForm.querySelector('[name="tender_' + name + '"]');
            if (field) {
                field.value = name === role && amount ? amount.value : '';
            }
        });
        if (scanField) {
            scanField.focus();
        }
    });
}
</script>
