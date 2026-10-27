<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $displayName */
/** @var string $dated */
/** @var string $monthName */
/** @var list<array{number: string, name: string, code: string, booth: string, amount: int, words: string}> $checks */
?>
<link rel="stylesheet" href="/assets/checks-print.css">
<div class="no-print">
    <p>One check for each vendor who is getting paid.</p>
    <button class="btn btn-primary" type="button" data-print>Print</button>
</div>
<?php foreach ($checks as $check): ?>
    <section class="check-sheet">
        <article class="check-face">
            <div class="check-top">
                <p class="check-mall"><?= View::text($displayName) ?></p>
                <p class="check-number">Check <?= View::text($check['number']) ?></p>
            </div>
            <p class="check-date"><?= View::text($dated) ?></p>
            <div class="check-pay">
                <p>Pay to the order of <?= View::text($check['name']) ?></p>
                <p class="check-amount"><?= View::text(View::money($check['amount'])) ?></p>
            </div>
            <p class="check-words"><?= View::text($check['words']) ?></p>
            <p class="check-memo">Memo: <?= View::text($monthName) ?> payout<?php if ($check['booth'] !== ''): ?>, booth <?= View::text($check['booth']) ?><?php endif; ?> (<?= View::text($check['code']) ?>)</p>
        </article>
    </section>
<?php endforeach; ?>
<script src="/assets/js/print-trigger.js"></script>
