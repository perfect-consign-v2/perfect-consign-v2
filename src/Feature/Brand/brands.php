<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var string $query */
/** @var int $total */
/** @var string $confirm */
/** @var string $modal */
/** @var string $draft */
/** @var list<array<string, mixed>> $brands */

$list = '/' . $slug . '/brands';
$renaming = $modal !== '' && $modal !== 'new';
$renameAction = $renaming ? '/' . $slug . '/brands/' . $modal : $list;
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Brands</h1>
        <p class="muted">The name shoppers and cashiers search for.</p>
    </div>
    <button class="btn btn-primary" type="button" data-open-modal="brand-new" data-brand-name=""><svg aria-hidden="true"><use href="#i-plus"></use></svg> New brand</button>
</div>

<?php if ($total === 0): ?>
    <div class="card">
        <div class="empty">
            <svg aria-hidden="true"><use href="#i-tag"></use></svg>
            <p>No brands yet.</p>
        </div>
    </div>
<?php else: ?>
    <form class="row gap-2 mt-6" method="get" action="<?= View::text($list) ?>" role="search">
        <input class="input" style="max-width:20rem" type="search" name="q" value="<?= View::text($query) ?>" placeholder="Search name" aria-label="Search brands">
        <button class="btn" type="submit">Search</button>
    </form>

    <div class="card table-wrap mt-4">
        <?php if ($brands === []): ?>
            <div class="empty"><p>No brands match.</p></div>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($brands as $brand): ?>
                    <?php
                    $id = is_string($brand['id'] ?? null) ? $brand['id'] : '';
                    $name = is_string($brand['name'] ?? null) ? $brand['name'] : '';
                    $deletable = ($brand['deletable'] ?? false) === true;
                    $keep = $query !== '' ? ['q' => $query] : [];
                    $cancel = $list . ($keep === [] ? '' : '?' . http_build_query($keep));
                    $ask = $keep;
                    $ask['confirm'] = $id;
                    $delete = $list . '?' . http_build_query($ask);
                    $rename = '/' . $slug . '/brands/' . $id;
                    ?>
                    <tr id="brand-<?= View::text($id) ?>">
                        <td><?= View::text($name) ?></td>
                        <td class="row gap-2">
                            <button class="btn btn-sm btn-ghost" type="button" data-open-modal="brand-rename" data-brand-action="<?= View::text($rename) ?>" data-brand-name="<?= View::text($name) ?>"><svg aria-hidden="true"><use href="#i-edit"></use></svg> Rename</button>
                            <?php if ($confirm === $id): ?>
                                <span>Delete this brand?</span>
                                <a class="btn btn-sm btn-ghost" href="<?= View::text($cancel) ?>">Cancel</a>
                                <form method="post" action="/<?= View::text($slug) ?>/brands/<?= View::text($id) ?>/delete">
                                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                    <button class="btn btn-sm" type="submit"><svg aria-hidden="true"><use href="#i-trash"></use></svg> Delete</button>
                                </form>
                            <?php elseif ($deletable): ?>
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

<div id="brand-new" class="modal" role="dialog" aria-modal="true" aria-labelledby="brand-new-title"<?= $modal === 'new' ? '' : ' hidden' ?>>
    <form class="modal-panel card" method="post" action="<?= View::text($list) ?>">
        <div class="card-head"><div class="h3" id="brand-new-title">New brand</div></div>
        <div class="card-body stack">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <label for="brand-new-name">Name</label>
            <input class="input" id="brand-new-name" name="name" value="<?= $modal === 'new' ? View::text($draft) : '' ?>" placeholder="e.g. Northwind" required>
            <div class="row gap-2">
                <button class="btn btn-primary" type="submit"><svg aria-hidden="true"><use href="#i-check"></use></svg> Create brand</button>
                <button class="btn" type="button" data-close-modal="brand-new">Cancel</button>
            </div>
        </div>
    </form>
</div>

<div id="brand-rename" class="modal" role="dialog" aria-modal="true" aria-labelledby="brand-rename-title"<?= $renaming ? '' : ' hidden' ?>>
    <form class="modal-panel card" method="post" action="<?= View::text($renameAction) ?>">
        <div class="card-head"><div class="h3" id="brand-rename-title">Rename brand</div></div>
        <div class="card-body stack">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <label for="brand-rename-name">Name</label>
            <input class="input" id="brand-rename-name" name="name" value="<?= $renaming ? View::text($draft) : '' ?>" required>
            <div class="row gap-2">
                <button class="btn btn-primary" type="submit"><svg aria-hidden="true"><use href="#i-check"></use></svg> Rename brand</button>
                <button class="btn" type="button" data-close-modal="brand-rename">Cancel</button>
            </div>
        </div>
    </form>
</div>
