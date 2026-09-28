<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var string $query */
/** @var int $total */
/** @var string $confirm */
/** @var list<array{id: string, code: string, description: string, price_minor: int|string, vendor_id: string, vendor_name: string, brand_name: string|null, deletable: bool}> $items */

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
        <input class="input" style="max-width:20rem" type="search" name="q" value="<?= View::text($query) ?>" placeholder="Search tag, description, vendor, or brand" aria-label="Search inventory">
        <button class="btn" type="submit">Search</button>
    </form>

    <div class="card table-wrap mt-4">
        <?php if ($items === []): ?>
            <div class="empty"><p>No pieces match.</p></div>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Tag</th>
                        <th>Description</th>
                        <th>Vendor</th>
                        <th>Brand</th>
                        <th class="num">Price</th>
                        <th></th>
                    </tr>
                </thead>
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
                        <td><?= View::text($item['description']) ?></td>
                        <td><a class="strong" href="/<?= View::text($slug) ?>/vendors/<?= View::text($item['vendor_id']) ?>"><?= View::text($item['vendor_name']) ?></a></td>
                        <td>
                            <?php if ($brand === null || $brand === ''): ?>
                                <span class="muted"><?= $blank ?></span>
                            <?php else: ?>
                                <?= View::text($brand) ?>
                            <?php endif; ?>
                        </td>
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
<?php endif; ?>
