<?php

declare(strict_types=1);

use PerfectConsign\Feature\Catalog\NameList;
use PerfectConsign\Feature\Inventory\InventorySelectionKey;
use PerfectConsign\Feature\Inventory\ItemColumns;
use PerfectConsign\Feature\Vendor\VendorPreview;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Web\ListTable;

/** @var string $slug */
/** @var string $csrf */
/** @var string $query */
/** @var string $stock */
/** @var int $available */
/** @var int $unsold */
/** @var int $unprinted */
/** @var int $total */
$stock = $stock ?? '';
$available = $available ?? 0;
$unsold = $unsold ?? 0;
$unprinted = $unprinted ?? 0;
$query = $query ?? '';
/** @var list<NameList> $attributeLists */
/** @var string $labelStock */
/** @var list<array{key: string, label: string}> $stocks */

$showBrand = ($showBrand ?? true) === true;
$previewAs = is_string($previewAs ?? null) ? $previewAs : '';
$list = '/' . $slug . '/vendor/inventory';
$lead = match ($stock) {
    'available' => 'Your items with quantity on hand — ready to sell.',
    'unprinted' => 'Your items that still need a sticker printed.',
    'out' => 'Your items with quantity zero — nothing left to sell.',
    default => 'One row is one item on the floor.',
};
$emptyFilter = match ($stock) {
    'available' => $query === '' ? 'No items with quantity on hand.' : 'No items match.',
    'unprinted' => $query === '' ? 'Every item already has a sticker.' : 'No items match.',
    'out' => $query === '' ? 'No items with quantity zero.' : 'No items match.',
    default => 'No items match.',
};
$shows = [
    '' => 'All (' . $total . ')',
    'available' => 'Available (' . $available . ')',
    'unprinted' => 'Need stickers (' . $unprinted . ')',
    'out' => 'Qty 0 (' . $unsold . ')',
];
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Your items</h1>
        <p class="muted"><?= View::text($lead) ?></p>
    </div>
    <a class="btn btn-primary" href="<?= View::text(VendorPreview::with('/' . $slug . '/vendor/inventory/new', $previewAs)) ?>">Add item</a>
</div>

<section class="vendor-age-legend" aria-labelledby="vendor-age-legend-title">
    <h2 class="h3" id="vendor-age-legend-title">Row colors</h2>
    <p class="muted text-sm">Available items are colored by how many days they have been on the floor.</p>
    <ul class="vendor-age-legend-list">
        <li><span class="vendor-age-swatch vendor-age-swatch--0"></span> 0–30 days</li>
        <li><span class="vendor-age-swatch vendor-age-swatch--1"></span> 31–60 days</li>
        <li><span class="vendor-age-swatch vendor-age-swatch--2"></span> 61–90 days</li>
        <li><span class="vendor-age-swatch vendor-age-swatch--3"></span> 91 or more days</li>
        <li><span class="vendor-age-swatch vendor-age-swatch--plain"></span> Quantity zero, or no intake date</li>
    </ul>
</section>

<?php if ($total === 0): ?>
    <div class="card">
        <div class="empty">
            <svg aria-hidden="true"><use href="#i-inventory"></use></svg>
            <p>No items yet.</p>
        </div>
    </div>
<?php else: ?>
    <div class="list-show mt-6">
        <p class="muted">Show</p>
        <div class="segmented" role="navigation" aria-label="Show items">
            <?php foreach ($shows as $value => $label): ?>
                <?php
                $params = [];
                if ($value !== '') {
                    $params['stock'] = $value;
                }
                if ($query !== '') {
                    $params['q'] = $query;
                }
                $href = VendorPreview::with(
                    $list . ($params === [] ? '' : '?' . http_build_query($params)),
                    $previewAs
                );
                $selected = $stock === $value;
                ?>
                <a class="seg-btn<?= $selected ? ' is-selected' : '' ?>" href="<?= View::text($href) ?>"<?= $selected ? ' aria-current="page"' : '' ?>><?= View::text($label) ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($stock === 'unprinted' && $unprinted > 0): ?>
        <p class="muted text-sm mt-3">Check items, then print from the bar at the bottom.</p>
    <?php endif; ?>

    <?php
    $printId = '';
    $lead = '';
    $labelAction = VendorPreview::with('/' . $slug . '/vendor/labels', $previewAs);
    $selectionStorageKey = InventorySelectionKey::vendor($slug);
    $stickerChrome = 'shell';
    require __DIR__ . '/../Inventory/sticker-job.php';
    $columns = ItemColumns::vendor($showBrand ? 'show' : 'hide', $attributeLists);
    $rowQuery = ['stock' => $stock];
    if ($query !== '') {
        $rowQuery['q'] = $query;
    }
    ?>

    <div class="card table-wrap list-dt-wrap list-dt-page-scroll mt-4">
        <?php
        ListTable::open([
            'id' => 'vendor-items',
            'export' => 'items',
            'pageScroll' => true,
            'server' => true,
            'rows' => VendorPreview::with($list . '/rows?' . http_build_query($rowQuery), $previewAs),
            'start' => 0,
            'empty' => $emptyFilter,
        ]);
        ListTable::head($columns);
        ?>
            <tbody></tbody>
        </table>
    </div>
    <?php require __DIR__ . '/../Inventory/selection-bar.php'; ?>
    <?php ListTable::assets(); ?>
<?php endif; ?>
