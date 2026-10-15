<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Scalar\RowValue;

/** @var string $templateDirectory */
/** @var string $slug */
/** @var string $displayName */
/** @var int|null $change */
/** @var array{
 *     journal_id: string,
 *     number: int,
 *     posted_on: string,
 *     vendor: string,
 *     booth: string,
 *     amount: int,
 *     cash: int,
 *     check_number: string,
 *     tenders: list<array{name: string, amount: int}>
 * } $slip */
$postedOn = DateTimeImmutable::createFromFormat('!Y-m-d', $slip['posted_on']);
$when = $postedOn instanceof DateTimeImmutable ? $postedOn->format('m-d-Y') : View::date($slip['posted_on']);
$booth = $slip['booth'] === '' ? '—' : $slip['booth'];
?>
<div class="slip-page">
    <div class="no-print row wrap gap-2">
        <button class="btn btn-primary" type="button" data-print>Print receipt</button>
        <a class="btn" href="/<?= View::text($slug) ?>/rent">Back to rent</a>
    </div>

    <article class="slip">
        <?php
        $kicker = 'Rent receipt';
        require $templateDirectory . '/Shared/Web/partials/slip-head.php';
        ?>
        <p class="slip-row"><span>Receipt</span><span><?= View::text((string) $slip['number']) ?></span></p>
        <p class="slip-row"><span>Date</span><span><?= View::text($when) ?></span></p>
        <p class="slip-row"><span>Vendor</span><span><?= View::text($slip['vendor']) ?></span></p>
        <p class="slip-row"><span>Booth</span><span><?= View::text($booth) ?></span></p>
        <hr class="slip-rule">
        <p class="slip-row slip-total"><span>Amount</span><span><?= View::text(View::money($slip['amount'])) ?></span></p>
        <?php if ($slip['tenders'] !== []): ?>
            <hr class="slip-rule">
            <?php foreach ($slip['tenders'] as $tender): ?>
                <p class="slip-row"><span><?= View::text($tender['name']) ?></span><span><?= View::text(View::money(RowValue::integer($tender['amount']))) ?></span></p>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php if ($slip['check_number'] !== ''): ?>
            <p class="slip-row"><span>Check number</span><span><?= View::text($slip['check_number']) ?></span></p>
        <?php endif; ?>
        <?php if (is_int($change)): ?>
            <p class="slip-row slip-total"><span>Change</span><span id="change"><?= View::text(View::money($change)) ?></span></p>
        <?php endif; ?>
        <p class="slip-thanks">Thank you</p>
    </article>
</div>
<script>
document.querySelectorAll('[data-print]').forEach(function (button) {
    button.addEventListener('click', function () {
        window.print();
    });
});
</script>
