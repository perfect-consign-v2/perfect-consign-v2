<?php

declare(strict_types=1);

use PerfectConsign\Feature\Inventory\InventorySelectionKey;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Web\ListTable;

/** @var string $slug */
/** @var string $csrf */
/** @var int $payable */
/** @var int $rent */
/** @var list<array{id: string, code: string, description: string, price_minor: int}> $pieces */
/** @var string $labelStock */
/** @var list<array{key: string, label: string}> $stocks */
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Your account</h1>
        <p class="muted">What the store owes you, and the rent you owe.</p>
    </div>
</div>
<div class="grid cols-2 mt-6">
    <div class="stat">
        <div class="stat-label">The store owes you</div>
        <div class="stat-value"><?= View::text(View::money($payable)) ?></div>
    </div>
    <div class="stat">
        <div class="stat-label">Rent you owe</div>
        <div class="stat-value"><?= View::text(View::money($rent)) ?></div>
    </div>
</div>
<?php if ($pieces !== []): ?>
    <?php
    $printId = '';
    $lead = 'Check your own pieces.';
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
                </tr>
            </thead>
            <tbody>
            <?php foreach ($pieces as $piece): ?>
                <tr>
                    <td><input form="stickers" type="checkbox" name="item[]" value="<?= View::text($piece['id']) ?>" aria-label="Print <?= View::text($piece['code']) ?>"></td>
                    <td><?= View::text($piece['code']) ?></td>
                    <td><?= View::text($piece['description']) ?></td>
                    <td><?= View::text(View::money($piece['price_minor'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php require __DIR__ . '/../Inventory/selection-bar.php'; ?>
    <?php ListTable::selectionScript(); ?>
<?php endif; ?>
