<?php

declare(strict_types=1);

use PerfectConsign\Feature\Vendor\VendorPreview;
use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var array{from: string, to: string, label: string} $span */
/** @var list<array{date: string, code: string, description: string, price: int, discount: int, sold: int, take: int, share: int}> $rows */
/** @var int $total */

$base = '/' . $slug . '/vendor/sales';
$previewAs = is_string($previewAs ?? null) ? $previewAs : '';
$salesLink = static function (string $query) use ($base, $previewAs): string {
    $path = $query === '' ? $base : $base . '?' . $query;

    return VendorPreview::with($path, $previewAs);
};
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Your sales</h1>
        <p class="muted"><?= View::text($span['label']) ?> · <?= View::date($span['from']) ?> to <?= View::date($span['to']) ?></p>
    </div>
</div>
<p class="mt-4">
    <a href="<?= View::text($salesLink('')) ?>">Since your last check</a>
    · <a href="<?= View::text($salesLink('range=check')) ?>">This check</a>
    · <a href="<?= View::text($salesLink('range=week')) ?>">This week</a>
    · <a href="<?= View::text($salesLink('range=month')) ?>">This month</a>
</p>
<form class="mt-4" method="get" action="<?= View::text($base) ?>">
    <input type="hidden" name="range" value="custom">
    <?php if ($previewAs !== ''): ?>
        <input type="hidden" name="as" value="<?= View::text($previewAs) ?>">
    <?php endif; ?>
    <div class="row gap-2">
        <div class="field">
            <label for="from">From</label>
            <input class="input" id="from" name="from" type="date" value="<?= View::text($span['from']) ?>">
        </div>
        <div class="field">
            <label for="to">To</label>
            <input class="input" id="to" name="to" type="date" min="<?= View::text($span['from']) ?>" value="<?= View::text($span['to']) ?>">
        </div>
        <p><button class="btn" type="submit">Show</button></p>
    </div>
</form>
<p class="h3 mt-6" id="sales-share">Your share <?= View::text(View::money($total)) ?></p>
<?php if ($rows === []): ?>
    <p class="muted mt-4">No sales in this range.</p>
<?php else: ?>
    <div class="card table-wrap mt-4">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Tag</th>
                    <th>Description</th>
                    <th>Price</th>
                    <th>Discount</th>
                    <th>Sold price</th>
                    <th>Store take</th>
                    <th>Your share</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= View::date($row['date']) ?></td>
                    <td><?= View::text($row['code']) ?></td>
                    <td><?= View::text($row['description']) ?></td>
                    <td><?= View::text(View::money($row['price'])) ?></td>
                    <td><?= View::text(View::money($row['discount'])) ?></td>
                    <td><?= View::text(View::money($row['sold'])) ?></td>
                    <td><?= View::text(View::money($row['take'])) ?></td>
                    <td><?= View::text(View::money($row['share'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
