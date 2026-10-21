<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var list<array{code: string, account_type: string, balance_minor: int}> $rows */
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Reports</h1>
        <p class="muted">Account balances from the ledger.</p>
    </div>
    <a href="/<?= View::text($slug) ?>/reports.csv">Download CSV</a>
</div>
<table>
    <thead>
        <tr><th>Account</th><th>Type</th><th>Balance</th></tr>
    </thead>
    <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= View::text($row['code']) ?></td>
                <td><?= View::text($row['account_type']) ?></td>
                <td><?= View::text((string) $row['balance_minor']) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
