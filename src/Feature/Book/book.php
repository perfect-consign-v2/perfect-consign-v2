<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var string $backupHeadline */
/** @var list<array<string, mixed>> $accounts */
/** @var list<array<string, mixed>> $periods */
?>
<h1>Book</h1>
<div class="card">
    <h2>Last backup you downloaded</h2>
    <p><?= View::text($backupHeadline) ?></p>
    <form method="post" action="/<?= View::text($slug) ?>/backup">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <input type="hidden" name="return" value="book">
        <p><button type="submit">Download backup</button></p>
    </form>
</div>
<div class="card">
    <h2>Open a period</h2>
    <form method="post" action="/<?= View::text($slug) ?>/periods">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <label for="starts_on">From</label>
        <input id="starts_on" name="starts_on" type="date" required>
        <label for="ends_on">To</label>
        <input id="ends_on" name="ends_on" type="date" required>
        <p><button type="submit">Add period</button></p>
    </form>
</div>
<h2>Periods</h2>
<table>
    <thead><tr><th>From</th><th>To</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($periods as $period): ?>
        <tr>
            <td><?= View::text($period['starts_on']) ?></td>
            <td><?= View::text($period['ends_on']) ?></td>
            <td><?= View::text($period['status']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<h2>Accounts</h2>
<table>
    <thead><tr><th>Code</th><th>Name</th><th>Type</th><th>Debits</th><th>Credits</th></tr></thead>
    <tbody>
    <?php foreach ($accounts as $account): ?>
        <tr>
            <td><?= View::text($account['code']) ?></td>
            <td><?= View::text($account['name']) ?></td>
            <td><?= View::text($account['account_type']) ?></td>
            <td><?= View::text(View::money(View::minor($account['debit_minor']))) ?></td>
            <td><?= View::text(View::money(View::minor($account['credit_minor']))) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
