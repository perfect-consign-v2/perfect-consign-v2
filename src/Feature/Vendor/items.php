<?php

declare(strict_types=1);

use PerfectConsign\Feature\Catalog\NameList;
use PerfectConsign\Feature\Inventory\InventorySelectionKey;
use PerfectConsign\Feature\Vendor\VendorPreview;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Web\ListTable;

/** @var string $slug */
/** @var string $csrf */
/** @var string $query */
/** @var string $stock */
/** @var int $unsold */
/** @var int $total */
$stock = $stock ?? '';
$unsold = $unsold ?? 0;
$query = $query ?? '';
/** @var list<array{id: string, code: string, barcode: string, description: string, price_minor: int|string, quantity: int, brand_name: string, color_name: string, category_name: string, size_name: string, condition_name: string, department_name: string, intake_on: string, expires_on: string, take_label: string, overridden: bool}> $items */
/** @var list<NameList> $attributeLists */
/** @var string $labelStock */
/** @var list<array{key: string, label: string}> $stocks */

$blank = '—';
$showBrand = ($showBrand ?? true) === true;
$previewAs = is_string($previewAs ?? null) ? $previewAs : '';
$list = '/' . $slug . '/vendor/inventory';
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Your items</h1>
        <p class="muted">One row is one item on the floor.</p>
    </div>
</div>

<?php if ($total === 0): ?>
    <div class="card">
        <div class="empty">
            <svg aria-hidden="true"><use href="#i-inventory"></use></svg>
            <p>No items yet.</p>
        </div>
    </div>
<?php else: ?>
    <form class="row gap-2 mt-6" method="get" action="<?= View::text($list) ?>" role="search">
        <?php if ($previewAs !== ''): ?>
            <input type="hidden" name="as" value="<?= View::text($previewAs) ?>">
        <?php endif; ?>
        <input class="input" style="max-width:20rem" type="search" name="q" value="<?= View::text($query) ?>" placeholder="<?= $showBrand ? 'Search tag, barcode, description, or brand' : 'Search tag, barcode, or description' ?>" aria-label="Search inventory">
        <button class="btn" type="submit">Search</button>
        <?php
        $chip = $list . '?stock=out';
        if ($query !== '') {
            $chip .= '&q=' . rawurlencode($query);
        }
        $chip = VendorPreview::with($chip, $previewAs);
        ?>
        <a class="occupancy-pill" href="<?= View::text($chip) ?>"<?= $stock === 'out' ? ' aria-current="page"' : '' ?>>Not for sale (<?= View::text((string) $unsold) ?>)</a>
    </form>

    <?php if ($items !== []): ?>
        <?php
        $printId = '';
        $lead = 'Check your own items.';
        $labelAction = VendorPreview::with('/' . $slug . '/vendor/labels', $previewAs);
        $selectionStorageKey = InventorySelectionKey::vendor($slug);
        require __DIR__ . '/../Inventory/sticker-job.php';
        ?>
    <?php endif; ?>

    <div class="card table-wrap list-dt-wrap list-dt-page-scroll mt-4">
        <?php if ($items === []): ?>
            <div class="empty"><p>No items match.</p></div>
        <?php else: ?>
            <?php
            $columns = [
                ['label' => 'Print', 'actions' => true, 'select' => true],
                ['label' => 'Tag'],
                ['label' => 'Barcode'],
                ['label' => 'Description'],
            ];
            if ($showBrand) {
                $columns[] = ['label' => 'Brand'];
            }
            foreach ($attributeLists as $attribute) {
                $columns[] = ['label' => ucfirst($attribute->singular)];
            }
            $columns[] = ['label' => 'Intake'];
            $columns[] = ['label' => 'Expires'];
            $columns[] = ['label' => 'Store take', 'sort' => 'num'];
            $columns[] = ['label' => 'Qty', 'sort' => 'num'];
            $columns[] = ['label' => 'Price', 'sort' => 'num'];
            ListTable::open(['id' => 'vendor-items', 'export' => 'items', 'pageScroll' => true]);
            ListTable::head($columns);
            ?>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <?php $brand = $item['brand_name']; ?>
                    <tr id="item-<?= View::text($item['id']) ?>">
                        <td><input form="stickers" type="checkbox" name="item[]" value="<?= View::text($item['id']) ?>" aria-label="Print <?= View::text($item['code']) ?>"></td>
                        <td class="strong"><?= View::text($item['code']) ?></td>
                        <td><?= View::text($item['barcode']) ?></td>
                        <td><?= View::text($item['description']) ?></td>
                        <?php if ($showBrand): ?>
                        <td>
                            <?php if ($brand === ''): ?>
                                <span class="muted"><?= $blank ?></span>
                            <?php else: ?>
                                <?= View::text($brand) ?>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <?php foreach ($attributeLists as $attribute): ?>
                            <?php $value = $item[$attribute->key . '_name']; ?>
                            <td>
                                <?php if ($value === ''): ?>
                                    <span class="muted"><?= $blank ?></span>
                                <?php else: ?>
                                    <?= View::text($value) ?>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                        <td><?= View::date($item['intake_on']) ?></td>
                        <td>
                            <?php if ($item['expires_on'] === ''): ?>
                                <span class="muted"><?= $blank ?></span>
                            <?php else: ?>
                                <?= View::date($item['expires_on']) ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= View::text($item['take_label']) ?>%
                            <?php if ($item['overridden']): ?>
                                <span class="badge badge-warning">Overridden</span>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?= View::text((string) $item['quantity']) ?></td>
                        <td class="num"><?= View::text(View::money(View::minor($item['price_minor']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php if ($items !== []): ?>
        <?php require __DIR__ . '/../Inventory/selection-bar.php'; ?>
    <?php endif; ?>
    <?php ListTable::assets(); ?>
<?php endif; ?>
