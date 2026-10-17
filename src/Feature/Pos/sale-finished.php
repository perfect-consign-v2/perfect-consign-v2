<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Scalar\RowValue;

/** @var string $slug */
/** @var string $csrf */
/** @var int|null $change */
/** @var ?array{
 *     journal_id: string,
 *     sale_number: int,
 *     posting_key: string,
 *     voided: bool,
 *     lines: list<array{item_id: string, code: string, description: string, vendor: string, price: int, quantity: int, tax: int, discount: int, returned: bool}>,
 *     tenders: list<array{name: string, amount: int}>,
 *     round: int,
 *     cash: int,
 *     discount: int,
 *     discount_reason: string,
 *     waiver: string
 * } $completed */
if ($completed === null) {
    return;
}
/** @param array{price: int, quantity: int, discount: int, store_discount?: int, tax: int} $line */
$charged = static function (array $line): int {
    $quantity = RowValue::integer($line['quantity']);
    $store = RowValue::integer($line['store_discount'] ?? 0);

    return RowValue::integer($line['price']) * ($quantity > 0 ? $quantity : 1)
        - RowValue::integer($line['discount'])
        - $store
        + RowValue::integer($line['tax']);
};
$cartTotal = 0;
foreach ($completed['lines'] as $line) {
    $cartTotal += $charged($line);
}
$round = RowValue::integer($completed['round']);
$paid = $cartTotal + $round;
$roundText = $round > 0 ? '+' . View::money($round) : View::money($round);
?>
<div id="sale-completed" class="modal" role="dialog" aria-modal="true" aria-labelledby="sale-completed-title" data-lock>
    <div class="modal-panel card register-finished">
    <div class="card-head">
        <button class="modal-x" type="submit" form="sale-new" aria-label="Close">
            <svg aria-hidden="true"><use href="#i-x"></use></svg>
        </button>
        <h2 class="h1" id="sale-completed-title"><?php if (is_int($change)): ?>Change due <?= View::text(View::money($change)) ?><?php else: ?>Receipt <?= View::text((string) $completed['sale_number']) ?><?php endif; ?></h2>
    </div>
    <div class="card-body stack">
        <?php if (is_int($change)): ?>
            <p class="h3">Receipt <?= View::text((string) $completed['sale_number']) ?></p>
        <?php endif; ?>
        <p>Review the change with the customer, then start a new sale.</p>
        <p>Scan and Instant inventory stay locked until you start a new sale.</p>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Line</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($completed['lines'] as $line): ?>
                        <tr>
                            <td>
                                <p><?= View::text($line['description']) ?></p>
                                <p class="ticket-meta"><?= View::text($line['code']) ?> <?= View::text($line['vendor']) ?></p>
                            </td>
                            <td><?= View::text(View::money($charged($line))) ?></td>
                            <td>
                                <?php if ($completed['voided'] !== true && $line['returned'] !== true): ?>
                                    <form method="post" action="/<?= View::text($slug) ?>/pos/receipt/<?= View::text($completed['journal_id']) ?>/return">
                                        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                        <input type="hidden" name="item_id" value="<?= View::text($line['item_id']) ?>">
                                        <input type="hidden" name="return_tender" value="cash">
                                        <button class="btn btn-sm" type="submit">Return</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="ticket-foot">
            <?php if ($completed['discount'] > 0): ?>
                <p class="register-summary">
                    <span>Discount <?= View::text($completed['discount_reason']) ?></span>
                    <span id="discount"><?= View::text(View::money(RowValue::integer($completed['discount']))) ?></span>
                </p>
            <?php endif; ?>
            <?php if ($completed['waiver'] !== ''): ?>
                <p><span class="badge" id="tax-waived">Tax waived</span> <?= View::text($completed['waiver']) ?></p>
            <?php endif; ?>
            <div class="pay-math">
                <p class="register-summary">
                    <span>Cart total</span>
                    <span><?= View::text(View::money($cartTotal)) ?></span>
                </p>
                <p class="register-summary">
                    <span>Cash round</span>
                    <span id="cash-round"><?= View::text($roundText) ?></span>
                </p>
                <p class="register-summary">
                    <span>Total paid</span>
                    <span class="register-due"><?= View::text(View::money($paid)) ?></span>
                </p>
            </div>
            <div class="pay-collected">
                <?php foreach ($completed['tenders'] as $tender): ?>
                    <p class="register-summary">
                        <span><?= View::text($tender['name']) ?></span>
                        <span><?= View::text(View::money(RowValue::integer($tender['amount']))) ?></span>
                    </p>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if (is_int($change)): ?>
            <p class="change-due" id="change">Change due <?= View::text(View::money($change)) ?></p>
        <?php endif; ?>
        <div class="row wrap gap-2">
            <form id="sale-new" method="post" action="/<?= View::text($slug) ?>/pos/new">
                <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                <button class="btn btn-primary" type="submit" autofocus>New sale</button>
            </form>
            <a class="btn" href="/<?= View::text($slug) ?>/pos/receipt/<?= View::text($completed['journal_id']) ?>?print=1">View receipt</a>
            <?php if ($completed['voided'] !== true): ?>
                <button class="btn" type="button" data-open-modal="void-ticket">Void ticket</button>
            <?php endif; ?>
        </div>
    </div>
    </div>
</div>
