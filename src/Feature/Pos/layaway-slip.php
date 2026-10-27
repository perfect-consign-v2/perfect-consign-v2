<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $templateDirectory */
/** @var string $slug */
/** @var string $displayName */
/** @var array{
 *     ticket_id: string,
 *     customer: string,
 *     cancelled: bool,
 *     lines: list<array{description: string, code: string, quantity: int, amount: int}>,
 *     due: int,
 *     deposits: int,
 *     balance: int,
 *     payments: list<array{posted_on: string, tender: string, amount: int, kind: string}>
 * } $slip
 */
?>
<div class="slip-page">
    <div class="no-print row wrap gap-2">
        <button class="btn btn-primary" type="button" data-print>Print receipt</button>
        <a class="btn" href="/<?= View::text($slug) ?>/pos">Back to register</a>
        <?php if ($slip['cancelled'] !== true): ?>
            <a class="btn" href="/<?= View::text($slug) ?>/pos/layaways">Layaways</a>
        <?php endif; ?>
    </div>
    <article class="slip">
        <?php
        $kicker = $slip['cancelled'] ? 'Layaway cancelled' : 'Layaway';
        require $templateDirectory . '/Shared/Web/partials/slip-head.php';
        ?>
        <p class="slip-row"><span>Customer</span><span><?= View::text($slip['customer'] !== '' ? $slip['customer'] : 'None') ?></span></p>
        <hr class="slip-rule">
        <?php foreach ($slip['lines'] as $line): ?>
            <div class="slip-item">
                <p class="slip-row">
                    <span><?= View::text($line['description']) ?></span>
                    <span><?= View::text(View::money($line['amount'])) ?></span>
                </p>
                <p class="slip-meta"><?= View::text((string) $line['quantity']) ?> · <?= View::text($line['code']) ?> · Held</p>
            </div>
        <?php endforeach; ?>
        <hr class="slip-rule">
        <p class="slip-row"><span>Amount due</span><span><?= View::text(View::money($slip['due'])) ?></span></p>
        <p class="slip-row"><span>Deposits</span><span><?= View::text(View::money($slip['deposits'])) ?></span></p>
        <p class="slip-row slip-total"><span>Balance</span><span><?= View::text(View::money($slip['balance'])) ?></span></p>
        <hr class="slip-rule">
        <p class="slip-kicker">Payments</p>
        <?php foreach ($slip['payments'] as $payment): ?>
            <p class="slip-row">
                <span><?= View::date($payment['posted_on']) ?> <?= View::text($payment['kind']) ?> <?= View::text($payment['tender']) ?></span>
                <span><?= View::text(View::money($payment['amount'])) ?></span>
            </p>
        <?php endforeach; ?>
        <?php if ($slip['cancelled'] !== true): ?>
            <p class="slip-thanks">Items stay here until the balance is paid</p>
        <?php else: ?>
            <p class="slip-thanks">Deposits refunded. Items are free.</p>
        <?php endif; ?>
    </article>
</div>
<script src="/assets/js/print-trigger.js"></script>
