<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Scalar\RowValue;

/** @var string $slug */
/** @var string $csrf */
/** @var array{
 *     journal_id: string,
 *     posting_key: string,
 *     description: string,
 *     voided: bool,
 *     lines: list<array{item_id: string, code: string, description: string, vendor: string, price: int, commission: int, tax: int, discount: int, returned: bool}>,
 *     tenders: list<array{name: string, amount: int}>,
 *     round: int,
 *     cash: int,
 *     discount: int,
 *     discount_reason: string,
 *     waiver: string
 * } $receipt
 */
/** @var int|null $change */
$lines = $receipt['lines'];
$tenders = $receipt['tenders'];
$voided = $receipt['voided'] === true;
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Receipt</h1>
        <p><?= View::text($receipt['posting_key']) ?></p>
        <?php if ($voided): ?>
            <p>This ticket was voided.</p>
        <?php endif; ?>
    </div>
</div>

<div class="card mt-6 register-ticket">
    <div class="card-body stack">
            <?php foreach ($lines as $line): ?>
                <div class="ticket-line">
                    <div>
                        <p><?= View::text($line['code']) ?> <?= View::text($line['description']) ?></p>
                        <p class="ticket-meta"><?= View::text($line['vendor']) ?></p>
                    </div>
                    <div class="ticket-line-end">
                        <p><?= View::text(View::money(RowValue::integer($line['price']))) ?></p>
                        <?php if ($line['returned'] === true): ?>
                            Returned
                        <?php elseif (!$voided): ?>
                            <form method="post" action="/<?= View::text($slug) ?>/pos/receipt/<?= View::text($receipt['journal_id']) ?>/return">
                                <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                <input type="hidden" name="item_id" value="<?= View::text($line['item_id']) ?>">
                                <label>Tender
                                    <select name="return_tender">
                                        <option value="cash">Cash</option>
                                        <option value="card">Card</option>
                                        <option value="checks">Check</option>
                                        <option value="gift_certificate">Gift certificate</option>
                                        <option value="gift_card">Gift card</option>
                                        <option value="store_credit">Store credit</option>
                                    </select>
                                </label>
                                <input class="input" name="return_check_number" aria-label="Check number">
                                <input class="input" name="return_number" aria-label="Stored value number">
                                <button class="btn" type="submit">Return</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="ticket-foot">
        <?php if ($receipt['discount'] > 0): ?>
            <p>Discount <span id="discount"><?= View::text(View::money(RowValue::integer($receipt['discount']))) ?></span> <?= View::text($receipt['discount_reason']) ?></p>
        <?php endif; ?>
        <?php if ($receipt['waiver'] !== ''): ?>
            <p><span class="badge" id="tax-waived">Tax waived</span> <?= View::text($receipt['waiver']) ?></p>
        <?php endif; ?>
        <?php foreach ($tenders as $tender): ?>
            <p><?= View::text($tender['name']) ?> <?= View::text(View::money(RowValue::integer($tender['amount']))) ?></p>
        <?php endforeach; ?>
        <?php if ($receipt['cash'] > 0): ?>
            <p>Cash round <span id="cash-round"><?= View::text(View::money(RowValue::integer($receipt['round']))) ?></span></p>
        <?php endif; ?>
        <?php if (is_int($change)): ?>
            <p>Change <span id="change"><?= View::text(View::money($change)) ?></span></p>
        <?php endif; ?>
        <p><a href="/<?= View::text($slug) ?>/journals/<?= View::text($receipt['journal_id']) ?>">Journal</a></p>
            </div>
    </div>
</div>

<?php if (!$voided): ?>
<button class="btn mt-6" type="button" data-open-modal="void-ticket">Void ticket</button>
<div id="void-ticket" class="modal" role="dialog" aria-modal="true" aria-labelledby="void-ticket-title" hidden>
    <form class="modal-panel card" method="post" action="/<?= View::text($slug) ?>/pos/receipt/<?= View::text($receipt['journal_id']) ?>/void">
        <div class="card-head"><div class="h3" id="void-ticket-title">Void ticket</div></div>
        <div class="card-body stack">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <p>Voiding reverses this sale and frees every piece.</p>
            <div class="row gap-2">
                <button class="btn btn-primary" type="submit">Confirm void</button>
                <button class="btn" type="button" data-close-modal="void-ticket">Cancel</button>
            </div>
        </div>
    </form>
</div>
<?php endif; ?>
