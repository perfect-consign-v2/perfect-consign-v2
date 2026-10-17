<?php

declare(strict_types=1);

use PerfectConsign\Feature\Catalog\NameList;
use PerfectConsign\Feature\Inventory\InventorySelectionKey;
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
/** @var string $confirm */
/** @var list<array{id: string, code: string, barcode: string, description: string, price_minor: int|string, quantity: int, vendor_id: string, vendor_label: string, brand_name: string, color_name: string, category_name: string, size_name: string, condition_name: string, department_name: string, intake_on: string, expires_on: string, take_label: string, overridden: bool, deletable: bool}> $items */
/** @var list<NameList> $attributeLists */
/** @var string $labelStock */
/** @var list<array{key: string, label: string}> $stocks */

$blank = '—';
$showBrand = ($showBrand ?? true) === true;
$list = '/' . $slug . '/inventory';
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Items</h1>
        <p class="muted">One row is one item on the floor.</p>
    </div>
    <div class="row gap-2">
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
    <form class="row gap-2 mt-6" method="get" action="<?= View::text($list) ?>" role="search">
        <input class="input" style="max-width:20rem" type="search" name="q" value="<?= View::text($query) ?>" placeholder="<?= $showBrand ? 'Search tag, barcode, description, consignor, or brand' : 'Search tag, barcode, description, or consignor' ?>" aria-label="Search inventory">
        <button class="btn" type="submit">Search</button>
        <?php
        $chip = $list . '?stock=out';
        if ($query !== '') {
            $chip .= '&q=' . rawurlencode($query);
        }
        ?>
        <a class="occupancy-pill" href="<?= View::text($chip) ?>"<?= $stock === 'out' ? ' aria-current="page"' : '' ?>>Not for sale (<?= View::text((string) $unsold) ?>)</a>
    </form>

    <?php if ($items !== []): ?>
        <?php
        $printId = '';
        $lead = 'Check the items in the list.';
        $selectionStorageKey = InventorySelectionKey::items($slug);
        require __DIR__ . '/sticker-job.php';
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
                ['label' => 'Consignor'],
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
            $columns[] = ['label' => '', 'actions' => true];
            ListTable::open(['id' => 'inventory', 'export' => 'inventory', 'pageScroll' => true]);
            ListTable::head($columns);
            ?>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <?php
                    $keep = $query !== '' ? ['q' => $query] : [];
                    $cancel = $list . ($keep === [] ? '' : '?' . http_build_query($keep));
                    $ask = $keep;
                    $ask['confirm'] = $item['id'];
                    $delete = $list . '?' . http_build_query($ask);
                    $brand = $item['brand_name'];
                    ?>
                    <tr id="item-<?= View::text($item['id']) ?>">
                        <td><input form="stickers" type="checkbox" name="item[]" value="<?= View::text($item['id']) ?>" aria-label="Print <?= View::text($item['code']) ?>"></td>
                        <td><a class="strong" href="/<?= View::text($slug) ?>/inventory/<?= View::text($item['id']) ?>/edit"><?= View::text($item['code']) ?></a></td>
                        <td><?= View::text($item['barcode']) ?></td>
                        <td><?= View::text($item['description']) ?></td>
                        <td><a class="strong" href="/<?= View::text($slug) ?>/vendors/<?= View::text($item['vendor_id']) ?>"><?= View::text($item['vendor_label']) ?></a></td>
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
                        <td class="row gap-2">
                            <a class="btn btn-sm btn-ghost" href="/<?= View::text($slug) ?>/inventory/<?= View::text($item['id']) ?>/edit"><svg aria-hidden="true"><use href="#i-edit"></use></svg> Edit</a>
                            <?php if ($confirm === $item['id']): ?>
                                <span>Delete this item?</span>
                                <a class="btn btn-sm btn-ghost" href="<?= View::text($cancel) ?>">Cancel</a>
                                <form method="post" action="/<?= View::text($slug) ?>/inventory/<?= View::text($item['id']) ?>/delete">
                                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                    <button class="btn btn-sm" type="submit"><svg aria-hidden="true"><use href="#i-trash"></use></svg> Delete</button>
                                </form>
                            <?php elseif ($item['deletable']): ?>
                                <a class="btn btn-sm" href="<?= View::text($delete) ?>"><svg aria-hidden="true"><use href="#i-trash"></use></svg> Delete</a>
                            <?php else: ?>
                                <span class="muted">In use</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php if ($items !== []): ?>
        <?php require __DIR__ . '/selection-bar.php'; ?>
    <?php endif; ?>
    <?php ListTable::assets(); ?>
<?php endif; ?>
