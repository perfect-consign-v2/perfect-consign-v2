<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var ?string $error */
/** @var list<array{id: string, name: string, source: string, grouped: string}> $saved */
?>
<h1>Report builder</h1>
<?php if ($error !== null && $error !== ''): ?>
    <p><?= View::text($error) ?></p>
<?php endif; ?>
<form method="post" action="/<?= View::text($slug) ?>/reports/build">
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
    <label for="name">Name</label>
    <input id="name" name="name" required maxlength="80">
    <label for="source">Source</label>
    <select id="source" name="source">
        <option value="sales">Sales</option>
        <option value="rent">Rent</option>
        <option value="payouts">Payouts</option>
        <option value="stored_value">Stored value</option>
    </select>
    <label for="grouped">Group</label>
    <select id="grouped" name="grouped">
        <option value="day">Day</option>
        <option value="tender">Tender</option>
        <option value="vendor">Vendor</option>
        <option value="cashier">Cashier</option>
        <option value="hour">Hour</option>
        <option value="category">Category</option>
        <option value="none">None</option>
    </select>
    <p>The sales-tax worksheet is not a saved report. It stays tied to the books.</p>
    <button type="submit">Save</button>
</form>
<ul>
    <?php foreach ($saved as $report): ?>
        <li><a href="/<?= View::text($slug) ?>/reports/saved/<?= View::text($report['id']) ?>"><?= View::text($report['name']) ?></a></li>
    <?php endforeach; ?>
</ul>
