<?php

declare(strict_types=1);

use PerfectConsign\Feature\Vendor\VendorPreview;
use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var array{
 *     paid_on: string,
 *     amount: int,
 *     number: string,
 *     lines: list<array{date: string, label: string, amount: int, run_id: string}>
 * } $check
 */
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Check <?= View::date($check['paid_on']) ?></h1>
        <p class="muted">
            <?= View::text(View::money($check['amount'])) ?>
            <?php if ($check['number'] !== ''): ?>
                · No. <?= View::text($check['number']) ?>
            <?php endif; ?>
        </p>
    </div>
    <a class="btn" href="<?= View::text(VendorPreview::with('/' . $slug . '/vendor', is_string($previewAs ?? null) ? $previewAs : '')) ?>">Your check</a>
</div>
<?php if ($check['lines'] === []): ?>
    <p class="muted mt-4">No lines on this check.</p>
<?php else: ?>
    <div class="card table-wrap mt-6">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>What</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($check['lines'] as $line): ?>
                <tr>
                    <td><?= View::date($line['date']) ?></td>
                    <td><?= View::text($line['label']) ?></td>
                    <td><?= View::text(View::money($line['amount'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
