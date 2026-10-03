<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Scalar\RowValue;

/** @var string $slug */
/** @var string $csrf */
/** @var list<array<string, mixed>> $runs */
/** @var list<array{code: string, name: string, mode: string}> $excluded */
/** @var string|null $error */
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Past payouts</h1>
    </div>
    <a class="btn" href="/<?= View::text($slug) ?>/payouts">Pay vendors</a>
</div>
<?php if (is_string($error) && $error !== ''): ?>
    <p class="alert"><?= View::text($error) ?></p>
<?php endif; ?>
<table class="table">
    <thead>
        <tr><th>Run</th><th>Period</th><th>Rent month</th><th>Tender</th><th>Status</th></tr>
    </thead>
    <tbody>
    <?php foreach ($runs as $run): ?>
        <tr>
            <td><?= View::text((string) RowValue::integer($run['run_no'] ?? null)) ?></td>
            <td><?= View::text(RowValue::string($run['sales_from'] ?? null)) ?> – <?= View::text(RowValue::string($run['sales_to'] ?? null)) ?></td>
            <td><?php $rentMonth = $run['rent_month'] ?? null; ?><?= is_string($rentMonth) && $rentMonth !== '' ? View::text($rentMonth) : '—' ?></td>
            <td><?= View::text(RowValue::string($run['tender'] ?? null)) ?></td>
            <td>
                <?= View::text(RowValue::string($run['status'] ?? null)) ?>
                <?php if (is_string($run['undone_by'] ?? null) && $run['undone_by'] !== '' && is_string($run['undone_reason'] ?? null) && $run['undone_reason'] !== '' && is_string($run['undone_at'] ?? null) && $run['undone_at'] !== ''): ?>
                    <div class="muted">Undone by <?= View::text($run['undone_by']) ?> on <?= View::text($run['undone_at']) ?> — <?= View::text($run['undone_reason']) ?></div>
                <?php endif; ?>
            </td>
            <td>
                <?php if (in_array($run['status'] ?? '', ['settled', 'paid'], true) && ($run['reverses_run_id'] ?? '') === ''): ?>
                    <form method="post" action="/<?= View::text($slug) ?>/payouts/undo">
                        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                        <input type="hidden" name="run_id" value="<?= View::text(RowValue::string($run['id'] ?? null)) ?>">
                        <input name="reason" required placeholder="Reason" aria-label="Reason">
                        <button type="submit">Undo this payout</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php if ($excluded !== []): ?>
    <p>Vendors excluded: <?php foreach ($excluded as $vendor): ?><?= View::text($vendor['name']) ?> <?php endforeach; ?></p>
<?php endif; ?>
