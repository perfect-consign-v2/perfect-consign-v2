<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $month */
/** @var bool $print */
/** @var list<array{section: string, label: string, sale_count: int, items_sold: int, subtotal_minor: int, discount_minor: int, tax_minor: int, total_minor: int}> $rows */
/** @var array{gross: int, exempt: int, returns: int, taxable: int, collected: int} $filing */
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Monthly sales tax</h1>
        <p class="muted"><?= View::text($month) ?>. Tax collected is the sales-tax payable posted in the month.</p>
    </div>
    <?php if (!$print): ?>
        <a href="/<?= View::text($slug) ?>/reports/sales-tax.csv?month=<?= View::text($month) ?>">Download CSV</a>
        <a href="/<?= View::text($slug) ?>/reports/sales-tax?month=<?= View::text($month) ?>&amp;print=1">Print</a>
    <?php endif; ?>
</div>
<?php if (!$print): ?>
<form method="get" action="/<?= View::text($slug) ?>/reports/sales-tax">
    <label for="month">Month</label>
    <input id="month" name="month" type="month" value="<?= View::text($month) ?>">
    <button type="submit">Show</button>
</form>
<?php endif; ?>
<p>Gross <?= View::text(View::money($filing['gross'])) ?>. Exempt <?= View::text(View::money($filing['exempt'])) ?>. Returns <?= View::text(View::money($filing['returns'])) ?>. Taxable base <?= View::text(View::money($filing['taxable'])) ?>. Tax collected <?= View::text(View::money($filing['collected'])) ?>.</p>
<p><a href="/<?= View::text($slug) ?>/tax?amount=<?= View::text(number_format($filing['collected'] / 100, 2, '.', '')) ?>">Post this tax payment</a></p>
<table>
    <thead>
        <tr><th>Section</th><th>Label</th><th>Sales</th><th>Items</th><th>Subtotal</th><th>Discounts</th><th>Tax</th><th>Total</th></tr>
    </thead>
    <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= View::text($row['section']) ?></td>
                <td><?= View::text($row['label']) ?></td>
                <td><?= View::text((string) $row['sale_count']) ?></td>
                <td><?= View::text((string) $row['items_sold']) ?></td>
                <td><?= View::text(View::money($row['subtotal_minor'])) ?></td>
                <td><?= View::text(View::money($row['discount_minor'])) ?></td>
                <td><?= View::text(View::money($row['tax_minor'])) ?></td>
                <td><?= View::text(View::money($row['total_minor'])) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
