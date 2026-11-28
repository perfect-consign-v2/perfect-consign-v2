<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var list<array{id: string, name: string, sheet: string, grouped: string, range_key: string}> $saved */

$base = '/' . $slug . '/reports';
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Reports</h1>
        <p class="muted">One question at a time. The dollars match the books.</p>
    </div>
</div>
<h2>Money</h2>
<ul>
    <li><a href="<?= View::text($base) ?>/sales-tax">Monthly sales tax</a> — What you file, and what you still owe.</li>
    <li><a href="<?= View::text($base) ?>/sales">Sales</a> — By day, tender, vendor, cashier, category, or hour.</li>
    <li><a href="<?= View::text($base) ?>/sales?group=hour">Hourly sales</a> — One day, hour by hour.</li>
    <li><a href="<?= View::text($base) ?>/rent">Rent still owed</a> — Open booth rent.</li>
    <li><a href="<?= View::text($base) ?>/rent-payments">Rent payments</a> — Every way rent was paid, with the register and cashier.</li>
    <li><a href="<?= View::text($base) ?>/bad-debts">Bad debts</a> — Rent and returns written off as a loss, who did it and why, and what was taken back.</li>
    <li><a href="<?= View::text($base) ?>/vendor-register">Vendor register</a> — Every money event for one vendor, with a running balance.</li>
    <li><a href="<?= View::text($base) ?>/payouts">Payments to vendors</a> — Checks, cash, the safe, and rent settlements.</li>
    <li><a href="<?= View::text($base) ?>/stored-value">Gift cards and store credit</a> — What the mall still owes.</li>
</ul>
<h2>Floor</h2>
<ul>
    <li><a href="<?= View::text($base) ?>/expiring">Expiring items</a> — Consigned pieces that expire in the next 14 days.</li>
    <li><a href="<?= View::text($base) ?>/donated">Donated items</a> — Pieces given to the store.</li>
    <li><a href="<?= View::text($base) ?>/sell-through">Category sell-through</a> — Sold in the range, and what is still here today.</li>
</ul>
<h2>Bookmarks</h2>
<p><a href="<?= View::text($base) ?>/build">Bookmarks</a> — A named shortcut to one of these pages.</p>
<?php if ($saved === []): ?>
    <p class="muted">No bookmarks yet.</p>
<?php else: ?>
    <ul>
        <?php foreach ($saved as $report): ?>
            <li><a href="<?= View::text($base) ?>/saved/<?= View::text($report['id']) ?>"><?= View::text($report['name']) ?></a></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
