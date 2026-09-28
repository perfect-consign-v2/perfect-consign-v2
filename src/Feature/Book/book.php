<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var list<array<string, mixed>> $accounts */
/** @var list<array<string, mixed>> $periods */
?>
<h1>Book</h1>
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
