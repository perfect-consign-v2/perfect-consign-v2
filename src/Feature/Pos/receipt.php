<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Scalar\RowValue;

/** @var string $slug */
/** @var string $csrf */
/** @var string $displayName */
/** @var int|null $change */
/** @var array{
 *     journal_id: string,
 *     posting_key: string,
 *     sale_number: int,
 *     posted_on: string,
 *     description: string,
 *     voided: bool,
 *     lines: list<array{item_id: string, code: string, description: string, vendor: string, price: int, quantity: int, commission: int, tax: int, discount: int, returned: bool}>,
 *     tenders: list<array{name: string, amount: int}>,
 *     round: int,
 *     cash: int,
 *     discount: int,
 *     discount_reason: string,
 *     waiver: string
 * } $receipt
 */
$lines = $receipt['lines'];
$tenders = $receipt['tenders'];
$voided = $receipt['voided'] === true;
$items = 0;
$tax = 0;
foreach ($lines as $line) {
    $quantity = RowValue::integer($line['quantity']);
    $items += RowValue::integer($line['price']) * ($quantity > 0 ? $quantity : 1);
    $tax += RowValue::integer($line['tax']);
}
$discount = RowValue::integer($receipt['discount']);
$round = RowValue::integer($receipt['round']);
$total = $items - $discount + $tax + $round;
$soldOn = DateTimeImmutable::createFromFormat('!Y-m-d', $receipt['posted_on']);
$when = $soldOn instanceof DateTimeImmutable ? $soldOn->format('m-d-Y') : View::date($receipt['posted_on']);
$roundText = $round > 0 ? '+' . View::money($round) : View::money($round);
?>
<div class="slip-page">
    <div class="no-print row wrap gap-2">
        <button class="btn btn-primary" type="button" data-print>Print receipt</button>
        <a class="btn" href="/<?= View::text($slug) ?>/pos">Back to register</a>
    </div>

    <article class="slip">
        <header class="slip-center">
            <p class="slip-store"><?= View::text($displayName) ?></p>
            <p class="slip-kicker">Receipt</p>
        </header>
        <?php if ($voided): ?>
            <p class="slip-void">Voided</p>
        <?php endif; ?>
        <p class="slip-row"><span>Sale</span><span><?= View::text((string) $receipt['sale_number']) ?></span></p>
        <p class="slip-row"><span>Date</span><span><?= View::text($when) ?></span></p>
        <hr class="slip-rule">
        <?php foreach ($lines as $line): ?>
            <?php
            $quantity = RowValue::integer($line['quantity']);
            $count = $quantity > 0 ? $quantity : 1;
            $amount = RowValue::integer($line['price']) * $count - RowValue::integer($line['discount']);
            ?>
            <div class="slip-item">
                <p class="slip-row">
                    <span><?= View::text($line['description']) ?></span>
                    <span><?= View::text(View::money($amount)) ?></span>
                </p>
                <p class="slip-meta"><?= View::text((string) $count) ?> · <?= View::text($line['code']) ?><?php if ($line['returned'] === true): ?> · Returned<?php endif; ?></p>
            </div>
        <?php endforeach; ?>
        <hr class="slip-rule">
        <p class="slip-row"><span>Subtotal</span><span><?= View::text(View::money($items)) ?></span></p>
        <?php if ($discount > 0): ?>
            <p class="slip-row"><span>Discount <?= View::text($receipt['discount_reason']) ?></span><span id="discount"><?= View::text(View::money($discount)) ?></span></p>
        <?php endif; ?>
        <?php if ($receipt['waiver'] !== ''): ?>
            <p class="slip-row"><span><span class="badge" id="tax-waived">Tax waived</span> <?= View::text($receipt['waiver']) ?></span></p>
        <?php else: ?>
            <p class="slip-row"><span>Tax</span><span><?= View::text(View::money($tax)) ?></span></p>
        <?php endif; ?>
        <?php if ($round !== 0): ?>
            <p class="slip-row"><span>Cash round</span><span id="cash-round"><?= View::text($roundText) ?></span></p>
        <?php endif; ?>
        <p class="slip-row slip-total"><span>Total</span><span><?= View::text(View::money($total)) ?></span></p>
        <?php if ($tenders !== []): ?>
            <hr class="slip-rule">
            <?php foreach ($tenders as $tender): ?>
                <p class="slip-row"><span><?= View::text($tender['name']) ?></span><span><?= View::text(View::money(RowValue::integer($tender['amount']))) ?></span></p>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php if (is_int($change)): ?>
            <p class="slip-row slip-total"><span>Change</span><span id="change"><?= View::text(View::money($change)) ?></span></p>
        <?php endif; ?>
        <p class="slip-thanks">Thank you</p>
    </article>

    <?php if (!$voided): ?>
        <section class="card mt-6 no-print">
            <div class="card-head"><h2 class="h3">Register</h2></div>
            <div class="card-body stack">
                <?php foreach ($lines as $line): ?>
                    <?php if ($line['returned'] === true) {
                        continue;
                    } ?>
                    <form class="stack" method="post" action="/<?= View::text($slug) ?>/pos/receipt/<?= View::text($receipt['journal_id']) ?>/return">
                        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                        <input type="hidden" name="item_id" value="<?= View::text($line['item_id']) ?>">
                        <p><?= View::text($line['description']) ?></p>
                        <div class="field">
                            <label for="return-tender-<?= View::text($line['item_id']) ?>">Refund tender</label>
                            <select class="input" id="return-tender-<?= View::text($line['item_id']) ?>" name="return_tender">
                                <option value="cash">Cash</option>
                                <option value="card">Card</option>
                                <option value="checks">Check</option>
                                <option value="gift_certificate">Gift certificate</option>
                                <option value="gift_card">Gift card</option>
                                <option value="store_credit">Store credit</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="return-check-<?= View::text($line['item_id']) ?>">Check number</label>
                            <input class="input" id="return-check-<?= View::text($line['item_id']) ?>" name="return_check_number">
                        </div>
                        <div class="field">
                            <label for="return-number-<?= View::text($line['item_id']) ?>">Stored value number</label>
                            <input class="input" id="return-number-<?= View::text($line['item_id']) ?>" name="return_number">
                        </div>
                        <button class="btn" type="submit">Return</button>
                    </form>
                <?php endforeach; ?>
                <div class="row wrap gap-2">
                    <button class="btn" type="button" data-open-modal="void-ticket">Void ticket</button>
                    <a class="btn" href="/<?= View::text($slug) ?>/journals/<?= View::text($receipt['journal_id']) ?>">Posted entry</a>
                </div>
            </div>
        </section>
            <section class="stack no-print">
                <h2 class="h3">Wrong booth</h2>
                <p class="muted">This moves what the store owes. The item price and the consignor on the item stay as they are.</p>
                <?php foreach ($lines as $line): ?>
                    <form class="stack" method="post" action="/<?= View::text($slug) ?>/pos/receipt/<?= View::text($receipt['journal_id']) ?>/booth">
                        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                        <input type="hidden" name="item_id" value="<?= View::text($line['item_id']) ?>">
                        <label for="correct-<?= View::text($line['item_id']) ?>"><?= View::text($line['code']) ?> was paid to <?= View::text($line['vendor']) ?></label>
                        <select class="select" id="correct-<?= View::text($line['item_id']) ?>" name="vendor_id" required>
                            <option value="">Pay this vendor instead</option>
                            <?php foreach ((is_array($vendors ?? null) ? $vendors : []) as $vendor): ?>
                                <?php if (is_array($vendor) && is_string($vendor['id'] ?? null)): ?>
                                    <option value="<?= View::text($vendor['id']) ?>"><?= View::text(is_string($vendor['name'] ?? null) ? $vendor['name'] : '') ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <p><button class="btn" type="submit">Move the payable</button></p>
                    </form>
                <?php endforeach; ?>
            </section>
        <div id="void-ticket" class="modal no-print" role="dialog" aria-modal="true" aria-labelledby="void-ticket-title" hidden>
            <form class="modal-panel card" method="post" action="/<?= View::text($slug) ?>/pos/receipt/<?= View::text($receipt['journal_id']) ?>/void">
                <div class="card-head"><div class="h3" id="void-ticket-title">Void ticket</div></div>
                <div class="card-body stack">
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <p>Voiding reverses this sale and frees every item.</p>
                    <div class="row wrap gap-2">
                        <button class="btn btn-primary" type="submit">Confirm void</button>
                        <button class="btn" type="button" data-close-modal="void-ticket">Cancel</button>
                    </div>
                </div>
            </form>
        </div>
    <?php endif; ?>
</div>
<script>
document.querySelectorAll('[data-print]').forEach(function (button) {
    button.addEventListener('click', function () {
        window.print();
    });
});
</script>
