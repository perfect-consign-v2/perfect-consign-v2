<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var string|null $error */
/** @var list<array{id: string, name: string, sheet: string, grouped: string, range_key: string}> $saved */

$base = '/' . $slug . '/reports';
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Bookmarks</h1>
        <p class="muted">A name for a report you already have. Sales tax stays on its own page.</p>
    </div>
</div>
<?php if ($error !== null && $error !== ''): ?>
    <p class="error"><?= View::text($error) ?></p>
<?php endif; ?>
<form method="post" action="<?= View::text($base) ?>/build">
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
    <div class="field">
        <label for="bookmark-name">Name</label>
        <input id="bookmark-name" name="name" required maxlength="80">
    </div>
    <div class="field">
        <label for="bookmark-sheet">Report</label>
        <select id="bookmark-sheet" name="sheet">
            <option value="sales">Sales</option>
            <option value="rent">Rent still owed</option>
            <option value="payouts">Payments to vendors</option>
            <option value="stored_value">Gift cards and store credit</option>
            <option value="expiring">Expiring items</option>
            <option value="donated">Donated items</option>
            <option value="sell_through">Category sell-through</option>
        </select>
    </div>
    <div class="field">
        <label for="bookmark-group">Sales grouping</label>
        <select id="bookmark-group" name="grouped">
            <option value="day">Day</option>
            <option value="tender">Tender</option>
            <option value="vendor">Vendor</option>
            <option value="cashier">Cashier</option>
            <option value="category">Category</option>
            <option value="hour">Hour</option>
            <option value="none">None</option>
        </select>
    </div>
    <div class="field">
        <label for="bookmark-range">Range</label>
        <select id="bookmark-range" name="range_key">
            <option value="today">Today</option>
            <option value="yesterday">Yesterday</option>
            <option value="this_week">This week</option>
            <option value="this_month" selected>This month</option>
            <option value="last_month">Last month</option>
            <option value="now">As of today</option>
        </select>
    </div>
    <p class="muted">Hour is today only. Rent, gift cards, and expiring items are as of today. Use None for every report except sales.</p>
    <button class="btn btn-primary" type="submit">Save</button>
</form>
<?php if ($saved !== []): ?>
    <ul>
        <?php foreach ($saved as $report): ?>
            <li>
                <a href="<?= View::text($base) ?>/saved/<?= View::text($report['id']) ?>"><?= View::text($report['name']) ?></a>
                <form method="post" action="<?= View::text($base) ?>/saved/<?= View::text($report['id']) ?>/delete">
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <button type="submit">Remove</button>
                </form>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
