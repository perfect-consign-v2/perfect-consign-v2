<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $displayName */
/** @var string $dated */
/** @var string $monthName */
/** @var list<array{number: string, name: string, code: string, booth: string, amount: int, words: string}> $checks */
?>
<style>
.check-sheet { break-after: page; page-break-after: always; margin-bottom: 1.5rem; }
.check-sheet:last-of-type { break-after: auto; page-break-after: auto; margin-bottom: 0; }
.check-face {
    max-width: 7.5in;
    min-height: 2.6in;
    border: 1px solid #1a2230;
    padding: 0.35in 0.45in;
    font-family: Georgia, "Times New Roman", serif;
    color: #000;
    background: #fff;
}
.check-top, .check-pay { display: flex; justify-content: space-between; gap: 1rem; }
.check-mall { margin: 0; font-size: 1.15rem; font-weight: 700; }
.check-number, .check-amount { margin: 0; font-weight: 700; white-space: nowrap; }
.check-date, .check-words, .check-memo { margin: 0.55rem 0 0; }
.check-amount { font-size: 1.15rem; }
@media print {
    .shell, .app { min-height: 0 !important; height: auto !important; }
    .check-face { border: 0; }
    .check-sheet:last-of-type { break-after: auto; page-break-after: auto; }
}
</style>
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
<script>
document.querySelectorAll('[data-print]').forEach(function (button) {
    button.addEventListener('click', function () {
        window.print();
    });
});
</script>
