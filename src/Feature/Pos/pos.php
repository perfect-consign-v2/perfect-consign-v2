<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Scalar\RowValue;

/** @var string $slug */
/** @var string $csrf */
/** @var array{
 *     ticket_id: string,
 *     lines: list<array{line_no: int, item_id: string, code: string, description: string, vendor: string, vendor_id: string, price: int, commission: int, tax: int}>,
 *     merchandise: int,
 *     tax: int,
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
        <p>Merchandise <span id="merchandise"><?= View::text(View::money(RowValue::integer($cart['merchandise']))) ?></span></p>
        <p>Tax <span id="tax"><?= View::text(View::money(RowValue::integer($cart['tax']))) ?></span></p>
        <p>Amount due <span id="amount-due"><?= View::text(View::money(RowValue::integer($cart['due']))) ?></span></p>
        <?php if ($cart['rounding'] === '1' && $lines !== []): ?>
            <p>Cash round <span id="cash-round"><?= View::text(View::money(RowValue::integer($cart['cash_round']))) ?></span></p>
            <p>Cash due <span id="cash-due"><?= View::text(View::money(RowValue::integer($cart['cash_due']))) ?></span></p>
        <?php endif; ?>
    </div>
</div>

<?php if ($lines !== []): ?>
<div class="card mt-6">
    <div class="card-head"><div class="h3">Pay</div></div>
    <div class="card-body">
        <form method="post" action="/<?= View::text($slug) ?>/pos/pay" class="grid cols-2">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <div class="field"><label for="tender_cash">Cash</label><input class="input" id="tender_cash" name="tender_cash" inputmode="decimal"></div>
            <div class="field"><label for="tender_card">Card</label><input class="input" id="tender_card" name="tender_card" inputmode="decimal"></div>
            <div class="field"><label for="tender_checks">Check</label><input class="input" id="tender_checks" name="tender_checks" inputmode="decimal"></div>
            <div class="field"><label for="check_number">Check number</label><input class="input" id="check_number" name="check_number"></div>
            <div class="field"><label for="tender_gift_certificate">Gift certificate</label><input class="input" id="tender_gift_certificate" name="tender_gift_certificate" inputmode="decimal"></div>
            <div class="field"><label for="gift_certificate_number">Gift certificate number</label><input class="input" id="gift_certificate_number" name="gift_certificate_number"></div>
            <div class="field"><label for="tender_gift_card">Gift card</label><input class="input" id="tender_gift_card" name="tender_gift_card" inputmode="decimal"></div>
            <div class="field"><label for="gift_card_number">Gift card number</label><input class="input" id="gift_card_number" name="gift_card_number"></div>
            <div class="field"><label for="tender_store_credit">Store credit</label><input class="input" id="tender_store_credit" name="tender_store_credit" inputmode="decimal"></div>
            <div class="field"><label for="store_credit_number">Store credit number</label><input class="input" id="store_credit_number" name="store_credit_number"></div>
            <div class="field"><label for="cash_received">Cash received</label><input class="input" id="cash_received" name="cash_received" inputmode="decimal"></div>
            <div class="field"><button class="btn btn-primary" type="submit">Pay</button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($cart['ticket_id'] !== ''): ?>
<div class="row gap-2 mt-6">
    <form method="post" action="/<?= View::text($slug) ?>/pos/park">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <button class="btn" type="submit">Park</button>
    </form>
    <form method="post" action="/<?= View::text($slug) ?>/pos/discard">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <button class="btn btn-ghost" type="submit">Discard</button>
    </form>
</div>
<?php endif; ?>

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

<script>
const scanField = document.getElementById('scan');
if (scanField) {
    scanField.focus();
}
</script>
