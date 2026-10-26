<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var list<array{id: string, name: string, source: string, grouped: string}> $saved */
$month = date('Y-m');
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Reports</h1>
        <p class="muted">The same numbers as the books.</p>
    </div>
</div>
<ul>
    <li><a href="/<?= View::text($slug) ?>/reports/sales-tax?month=<?= View::text($month) ?>">Monthly sales tax</a></li>
    <li><a href="/<?= View::text($slug) ?>/reports/sales">Sales</a></li>
    <li><a href="/<?= View::text($slug) ?>/reports/sales?group=hour">Hourly sales</a></li>
    <li><a href="/<?= View::text($slug) ?>/reports/sell-through">Category sell-through</a></li>
    <li><a href="/<?= View::text($slug) ?>/reports/expiring">Expiring items</a></li>
    <li><a href="/<?= View::text($slug) ?>/reports/donated">Donated items</a></li>
    <li><a href="/<?= View::text($slug) ?>/reports/rent">Rent roll</a></li>
    <li><a href="/<?= View::text($slug) ?>/reports/payouts">Payout register</a></li>
    <li><a href="/<?= View::text($slug) ?>/reports/stored-value">Stored value</a></li>
    <li><a href="/<?= View::text($slug) ?>/reports/build">Report builder</a></li>
</ul>
<?php if ($saved !== []): ?>
    <h2>Saved</h2>
    <ul>
        <?php foreach ($saved as $report): ?>
            <li><a href="/<?= View::text($slug) ?>/reports/saved/<?= View::text($report['id']) ?>"><?= View::text($report['name']) ?></a></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
