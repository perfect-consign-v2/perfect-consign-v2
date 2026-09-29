<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Scalar\RowValue;

/** @var string $slug */
/** @var string $csrf */
/** @var int|null $change */
/** @var ?array{
 *     journal_id: string,
 *     posting_key: string,
 *     voided: bool,
 *     lines: list<array{item_id: string, code: string, description: string, vendor: string, price: int, returned: bool}>,
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
?>
<article class="card register-finished">
    <div class="card-head"><div class="h3">Sale completed</div></div>
    <div class="card-body stack">
        <p><?= View::text($completed['posting_key']) ?></p>
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
                            <td><?= View::text(View::money(RowValue::integer($line['price']))) ?></td>
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
            <?php foreach ($completed['tenders'] as $tender): ?>
                <p class="register-summary">
                    <span><?= View::text($tender['name']) ?></span>
                    <span><?= View::text(View::money(RowValue::integer($tender['amount']))) ?></span>
                </p>
            <?php endforeach; ?>
            <?php if ($completed['round'] !== 0): ?>
                <p class="register-summary">
                    <span>Cash round</span>
                    <span id="cash-round"><?= View::text(View::money(RowValue::integer($completed['round']))) ?></span>
                </p>
            <?php endif; ?>
            <p class="register-summary">
                <span><?= is_int($change) ? 'Change due' : 'Total' ?></span>
                <span class="register-due" id="change"><?= is_int($change) ? View::text(View::money($change)) : View::text(View::money(RowValue::integer($completed['cash']))) ?></span>
            </p>
        </div>
        <div class="row gap-2">
            <form method="post" action="/<?= View::text($slug) ?>/pos/new">
                <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                <button class="btn btn-primary" type="submit">New sale</button>
            </form>
            <a class="btn" href="/<?= View::text($slug) ?>/pos/receipt/<?= View::text($completed['journal_id']) ?>">View receipt</a>
            <?php if ($completed['voided'] !== true): ?>
                <button class="btn" type="button" data-open-modal="void-ticket">Void ticket</button>
            <?php endif; ?>
        </div>
    </div>
</article>
