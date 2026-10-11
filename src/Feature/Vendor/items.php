<?php

declare(strict_types=1);

use PerfectConsign\Feature\Inventory\InventorySelectionKey;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Web\ListTable;

/** @var string $slug */
/** @var string $csrf */
/** @var list<array{id: string, code: string, description: string, price_minor: int, quantity: int, sold_on: string}> $items */
/** @var string $labelStock */
/** @var list<array{key: string, label: string}> $stocks */
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Your items</h1>
    </div>
</div>
<?php if ($items === []): ?>
    <p class="muted mt-4">No items yet.</p>
<?php else: ?>
    <?php
    $printId = '';
    $lead = 'Check your own items.';
    $labelAction = '/' . $slug . '/vendor/labels';
    $selectionStorageKey = InventorySelectionKey::vendor($slug);
    require __DIR__ . '/../Inventory/sticker-job.php';
    ?>
    <div class="card table-wrap mt-4">
        <table data-inventory-selection="plain">
            <thead>
                <tr>
                    <th>Print</th>
                    <th>Tag</th>
                    <th>Description</th>
                    <th>Price</th>
                    <th>On hand</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td><input form="stickers" type="checkbox" name="item[]" value="<?= View::text($item['id']) ?>" aria-label="Print <?= View::text($item['code']) ?>"></td>
                    <td><?= View::text($item['code']) ?></td>
                    <td><?= View::text($item['description']) ?></td>
                    <td><?= View::text(View::money($item['price_minor'])) ?></td>
                    <td><?= $item['quantity'] > 0
                        ? View::text((string) $item['quantity']) . ' on hand'
                        : 'Sold ' . View::text($item['sold_on']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php require __DIR__ . '/../Inventory/selection-bar.php'; ?>
    <?php ListTable::selectionScript(); ?>
<?php endif; ?>
