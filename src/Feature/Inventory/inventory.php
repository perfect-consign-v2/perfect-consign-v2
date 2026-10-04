<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Web\ListTable;

/** @var string $slug */
/** @var string $csrf */
/** @var string $query */
/** @var int $total */
/** @var string $confirm */
/** @var list<array{id: string, code: string, barcode: string, description: string, price_minor: int|string, quantity: int, vendor_id: string, vendor_label: string, brand_name: string, intake_on: string, expires_on: string, take_label: string, overridden: bool, deletable: bool}> $items */

$blank = '—';
$list = '/' . $slug . '/inventory';
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Inventory</h1>
        <p class="muted">One row is one piece on the floor.</p>
    </div>
    <a class="btn btn-primary" href="/<?= View::text($slug) ?>/inventory/new"><svg aria-hidden="true"><use href="#i-plus"></use></svg> New piece</a>
</div>

<?php if ($total === 0): ?>
    <div class="card">
        <div class="empty">
            <svg aria-hidden="true"><use href="#i-inventory"></use></svg>
            <p>No pieces yet.</p>
        </div>
    </div>
<?php else: ?>
    <form class="row gap-2 mt-6" method="get" action="<?= View::text($list) ?>" role="search">
        <input class="input" style="max-width:20rem" type="search" name="q" value="<?= View::text($query) ?>" placeholder="Search tag, barcode, description, consignor, or brand" aria-label="Search inventory">
        <button class="btn" type="submit">Search</button>
    </form>

    <div class="card table-wrap list-dt-wrap mt-4">
        <?php if ($items === []): ?>
            <div class="empty"><p>No pieces match.</p></div>
        <?php else: ?>
            <?php
            ListTable::open(['id' => 'inventory', 'export' => 'inventory']);
            ListTable::head([
                ['label' => 'Tag'],
                ['label' => 'Barcode'],
                ['label' => 'Description'],
                ['label' => 'Consignor'],
                ['label' => 'Brand'],
                ['label' => 'Intake'],
                ['label' => 'Expires'],
                ['label' => 'Store take', 'sort' => 'num'],
                ['label' => 'Qty', 'sort' => 'num'],
                ['label' => 'Price', 'sort' => 'num'],
                ['label' => '', 'actions' => true],
            ]);
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
                        <td><a class="strong" href="/<?= View::text($slug) ?>/inventory/<?= View::text($item['id']) ?>/edit"><?= View::text($item['code']) ?></a></td>
                        <td><?= View::text($item['barcode']) ?></td>
                        <td><?= View::text($item['description']) ?></td>
                        <td><a class="strong" href="/<?= View::text($slug) ?>/vendors/<?= View::text($item['vendor_id']) ?>"><?= View::text($item['vendor_label']) ?></a></td>
                        <td>
                            <?php if ($brand === ''): ?>
                                <span class="muted"><?= $blank ?></span>
                            <?php else: ?>
                                <?= View::text($brand) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= View::text($item['intake_on']) ?></td>
                        <td>
                            <?php if ($item['expires_on'] === ''): ?>
                                <span class="muted"><?= $blank ?></span>
                            <?php else: ?>
                                <?= View::text($item['expires_on']) ?>
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
                                <span>Delete this piece?</span>
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
    <?php ListTable::assets(); ?>
<?php endif; ?>
