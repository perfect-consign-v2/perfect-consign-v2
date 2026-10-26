<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $kind */
/** @var list<array<string, int|string>> $rows */
$csv = match ($kind) {
    'sales' => '/reports/sales.csv',
    'rent' => '/reports/rent.csv',
    'payouts' => '/reports/payouts.csv',
    'stored' => '/reports/stored-value.csv',
    'expiring' => '/reports/expiring.csv',
    'donated' => '/reports/donated.csv',
    'sell-through' => '/reports/sell-through.csv',
    default => '',
};
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1"><?= View::text($title ?? 'Report') ?></h1>
    </div>
    <?php if ($csv !== ''): ?>
        <a href="/<?= View::text($slug) . View::text($csv) ?>">Download CSV</a>
    <?php endif; ?>
</div>
<table>
    <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <?php foreach ($row as $value): ?>
                    <td><?= View::text((string) $value) ?></td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
