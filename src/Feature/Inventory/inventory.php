<?php

declare(strict_types=1);

use PerfectConsign\Feature\Catalog\NameList;
use PerfectConsign\Feature\Inventory\InventorySelectionKey;
use PerfectConsign\Feature\Inventory\ItemColumns;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Web\ListTable;

/** @var string $slug */
/** @var string $csrf */
/** @var string $query */
/** @var string $stock */
/** @var int $available */
/** @var int $unsold */
/** @var int $sold */
/** @var int $unprinted */
/** @var int $total */
$stock = $stock ?? '';
$available = $available ?? 0;
$unsold = $unsold ?? 0;
$sold = $sold ?? 0;
$unprinted = $unprinted ?? 0;
/** @var string $confirm */
/** @var int $confirmIndex */
/** @var list<NameList> $attributeLists */
/** @var string $labelStock */
/** @var list<array{key: string, label: string}> $stocks */

$showBrand = ($showBrand ?? true) === true;
$sellerLabel = ($consignmentOnly ?? false) === true ? 'Consignor' : 'Vendor';
$confirmIndex = is_int($confirmIndex ?? null) ? $confirmIndex : 0;
$list = '/' . $slug . '/inventory';
$lead = match ($stock) {
    'available' => 'Items with quantity on hand — ready to sell.',
    'unprinted' => 'Items that still need a sticker printed.',
    'out' => 'Items with quantity zero — nothing left to sell.',
    'sold' => 'Items that have posted on a sale and have quantity zero.',
    default => 'One row is one item on the floor.',
};
$emptyFilter = match ($stock) {
    'available' => $query === '' ? 'No items with quantity on hand.' : 'No items match.',
    'unprinted' => $query === '' ? 'Every item already has a sticker.' : 'No items match.',
    'out' => $query === '' ? 'No items with quantity zero.' : 'No items match.',
    'sold' => $query === '' ? 'No sold items yet.' : 'No items match.',
    default => 'No items match.',
};
$shows = [
    '' => 'All (' . $total . ')',
    'available' => 'Available (' . $available . ')',
    'sold' => 'Sold (' . $sold . ')',
    'unprinted' => 'Need stickers (' . $unprinted . ')',
    'out' => 'Qty 0 (' . $unsold . ')',
];
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Items</h1>
        <p class="muted"><?= View::text($lead) ?></p>
    </div>
    <div class="row gap-2">
        <a class="btn" href="/<?= View::text($slug) ?>/inventory/expired">Expired items</a>
        <a class="btn" href="/<?= View::text($slug) ?>/inventory/import">Bring in tags</a>
        <a class="btn btn-primary" href="/<?= View::text($slug) ?>/inventory/new"><svg aria-hidden="true"><use href="#i-plus"></use></svg> New item</a>
    </div>
</div>

<?php if ($total === 0): ?>
    <div class="card">
        <div class="empty">
            <svg aria-hidden="true"><use href="#i-inventory"></use></svg>
            <p>No items yet. Add one item, or bring in a list of tags.</p>
            <p><a class="btn btn-primary" href="/<?= View::text($slug) ?>/inventory/import">Bring in tags</a></p>
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
                $href = $list . ($params === [] ? '' : '?' . http_build_query($params));
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
    $selectionStorageKey = InventorySelectionKey::items($slug);
    $stickerChrome = 'shell';
    require __DIR__ . '/sticker-job.php';
    $columns = ItemColumns::staff($showBrand ? 'show' : 'hide', $attributeLists, $sellerLabel);
    $rowQuery = ['stock' => $stock];
    if ($query !== '') {
        $rowQuery['q'] = $query;
    }
    if ($confirm !== '') {
        $rowQuery['confirm'] = $confirm;
    }
    ?>

    <div class="card table-wrap list-dt-wrap list-dt-page-scroll mt-4">
        <?php
        ListTable::open([
            'id' => 'inventory',
            'export' => 'inventory',
            'pageScroll' => true,
            'server' => true,
            'rows' => $list . '/rows?' . http_build_query($rowQuery),
            'start' => $confirmIndex,
            'empty' => $emptyFilter,
        ]);
        ListTable::head($columns);
        ?>
            <tbody></tbody>
        </table>
    </div>
    <?php require __DIR__ . '/selection-bar.php'; ?>
    <?php ListTable::assets(); ?>
<?php endif; ?>
