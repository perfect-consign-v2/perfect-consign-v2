<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Scalar\RowValue;

/** @var string $slug */
/** @var list<array<string, mixed>> $runs */
/** @var list<array{code: string, name: string, mode: string}> $excluded */
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Past payouts</h1>
    </div>
    <a class="btn" href="/<?= View::text($slug) ?>/payouts">Pay vendors</a>
</div>
<table class="table">
    <thead>
        <tr><th>Run</th><th>Period</th><th>Rent month</th><th>Tender</th><th>Status</th></tr>
    </thead>
    <tbody>
    <?php foreach ($runs as $run): ?>
        <tr>
            <td><?= View::text(RowValue::string($run['run_no'] ?? null)) ?></td>
            <td><?= View::text(RowValue::string($run['sales_from'] ?? null)) ?> – <?= View::text(RowValue::string($run['sales_to'] ?? null)) ?></td>
            <td><?= View::text(RowValue::string($run['rent_month'] ?? null)) ?></td>
            <td><?= View::text(RowValue::string($run['tender'] ?? null)) ?></td>
            <td><?= View::text(RowValue::string($run['status'] ?? null)) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php if ($excluded !== []): ?>
    <p>Vendors excluded: <?php foreach ($excluded as $vendor): ?><?= View::text($vendor['name']) ?> <?php endforeach; ?></p>
<?php endif; ?>
