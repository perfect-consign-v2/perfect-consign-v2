<?php

declare(strict_types=1);

use PerfectConsign\Feature\Catalog\DeletePrompt;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Web\ListTable;

/** @var string $templateDirectory */
/** @var string $slug */
/** @var string $csrf */
/** @var string $query */
/** @var string $standing */
/** @var int $activeCount */
/** @var int $inactiveCount */
/** @var int $total */
/** @var string $confirm */
/** @var string $merge */
/** @var string $mergeSentence */
/** @var list<array<string, mixed>> $mergeChoices */
/** @var string $modal */
/** @var string $draft */
/** @var list<array<string, mixed>> $brands */

$list = '/' . $slug . '/brands';
$renaming = $modal !== '' && $modal !== 'new';
$renameAction = $renaming ? '/' . $slug . '/brands/' . $modal : $list;
$confirmMessage = DeletePrompt::sentence('brand');
$confirmAction = $list;
$keep = [];
if ($query !== '') {
    $keep['q'] = $query;
}
if ($standing === 'inactive') {
    $keep['status'] = 'inactive';
}
$back = $list . ($keep === [] ? '' : '?' . http_build_query($keep));
$empty = $query !== '' ? 'No brands match.' : ($standing === 'inactive' ? 'No inactive brands.' : 'No active brands.');
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
    <?php
    $listBase = $list;
    require $templateDirectory . '/Feature/Catalog/name-standing.php';
    ?>
    <div class="card table-wrap list-dt-wrap mt-4">
        <?php if ($brands === []): ?>
            <div class="empty"><p><?= View::text($empty) ?></p></div>
        <?php else: ?>
            <?php
            ListTable::open(['id' => 'brands', 'export' => 'brands']);
            ListTable::head([
                ['label' => 'Name'],
                ['label' => '', 'actions' => true],
            ]);
            ?>
                <tbody>
                <?php foreach ($brands as $brand): ?>
                    <?php
                    $id = is_string($brand['id'] ?? null) ? $brand['id'] : '';
                    $name = is_string($brand['name'] ?? null) ? $brand['name'] : '';
                    $active = ($brand['active'] ?? true) === true;
                    $deletable = ($brand['deletable'] ?? false) === true;
                    $ask = $keep;
                    $ask['confirm'] = $id;
                    $delete = $list . '?' . http_build_query($ask);
                    $deleteAction = '/' . $slug . '/brands/' . $id . '/delete';
                    $mergeAsk = $keep;
                    $mergeAsk['merge'] = $id;
                    $mergeLink = $list . '?' . http_build_query($mergeAsk);
                    $rename = '/' . $slug . '/brands/' . $id;
                    if ($confirm === $id) {
                        $confirmAction = $deleteAction;
                    }
                    ?>
                    <tr id="brand-<?= View::text($id) ?>">
                        <td><?= View::text($name) ?><?php if (!$active): ?> <span class="muted">Inactive</span><?php endif; ?></td>
                        <td class="row gap-2">
                            <button class="btn btn-sm btn-ghost" type="button" data-open-modal="brand-rename" data-brand-action="<?= View::text($rename) ?>" data-brand-name="<?= View::text($name) ?>"><svg aria-hidden="true"><use href="#i-edit"></use></svg> Rename</button>
                            <?php if (!$active): ?>
                                <form class="row" method="post" action="<?= View::text($rename) ?>/active">
                                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                    <input type="hidden" name="active" value="1">
                                    <button class="btn btn-sm" type="submit">Make active</button>
                                </form>
                            <?php elseif (!$deletable): ?>
                                <form class="row" method="post" action="<?= View::text($rename) ?>/active">
                                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                    <input type="hidden" name="active" value="0">
                                    <button class="btn btn-sm" type="submit">Make inactive</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($total > 1): ?>
                                <a class="btn btn-sm" href="<?= View::text($mergeLink) ?>">Merge</a>
                            <?php endif; ?>
                            <?php if ($deletable): ?>
                                <a class="btn btn-sm" href="<?= View::text($delete) ?>" data-open-modal="brand-delete" data-brand-action="<?= View::text($deleteAction) ?>" data-delete-message="<?= View::text($confirmMessage) ?>"><svg aria-hidden="true"><use href="#i-trash"></use></svg> Delete</a>
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

<div id="brand-new" class="modal" role="dialog" aria-modal="true" aria-labelledby="brand-new-title"<?= $modal === 'new' ? '' : ' hidden' ?>>
    <form class="modal-panel card" method="post" action="<?= View::text($list) ?>">
        <div class="card-head">
            <?php $closeModal = 'brand-new'; require $templateDirectory . '/Shared/Web/modal-x.php'; ?>
            <div class="h3" id="brand-new-title">New brand</div>
        </div>
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
        <div class="card-head">
            <?php $closeModal = 'brand-rename'; require $templateDirectory . '/Shared/Web/modal-x.php'; ?>
            <div class="h3" id="brand-rename-title">Rename brand</div>
        </div>
        <div class="card-body stack">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <?php if ($standing === 'inactive'): ?>
                <input type="hidden" name="status" value="inactive">
            <?php endif; ?>
            <label for="brand-rename-name">Name</label>
            <input class="input" id="brand-rename-name" name="name" value="<?= $renaming ? View::text($draft) : '' ?>" required>
            <div class="row gap-2">
                <button class="btn btn-primary" type="submit"><svg aria-hidden="true"><use href="#i-check"></use></svg> Rename brand</button>
                <button class="btn" type="button" data-close-modal="brand-rename">Cancel</button>
            </div>
        </div>
    </form>
</div>

<div id="brand-delete" class="modal" role="dialog" aria-modal="true" aria-labelledby="brand-delete-title"<?= $confirm === '' ? ' hidden' : '' ?>>
    <form class="modal-panel card" method="post" action="<?= View::text($confirmAction) ?>">
        <div class="card-head">
            <?php $closeModal = 'brand-delete'; require $templateDirectory . '/Shared/Web/modal-x.php'; ?>
            <div class="h3" id="brand-delete-title">Delete brand</div>
        </div>
        <div class="card-body stack">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <?php if ($standing === 'inactive'): ?>
                <input type="hidden" name="status" value="inactive">
            <?php endif; ?>
            <p data-delete-prompt><?= View::text($confirmMessage) ?></p>
            <div class="row gap-2">
                <button class="btn btn-primary" type="submit">Delete</button>
                <button class="btn" type="button" data-close-modal="brand-delete">Cancel</button>
            </div>
        </div>
    </form>
</div>

<?php if ($merge !== '' && $mergeChoices !== []): ?>
<div id="brand-merge" class="modal" role="dialog" aria-modal="true" aria-labelledby="brand-merge-title">
    <form class="modal-panel card" method="post" action="<?= View::text($list . '/' . $merge . '/merge') ?>">
        <div class="card-head">
            <?php $closeModal = 'brand-merge'; require $templateDirectory . '/Shared/Web/modal-x.php'; ?>
            <div class="h3" id="brand-merge-title">Merge brand</div>
        </div>
        <div class="card-body stack">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <p><?= View::text($mergeSentence) ?></p>
            <label for="brand-merge-target">Keep</label>
            <select class="select" id="brand-merge-target" name="target" required>
                <?php foreach ($mergeChoices as $choice): ?>
                    <?php
                    $choiceId = is_string($choice['id'] ?? null) ? $choice['id'] : '';
                    $choiceName = is_string($choice['name'] ?? null) ? $choice['name'] : '';
                    ?>
                    <option value="<?= View::text($choiceId) ?>"><?= View::text($choiceName) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="row gap-2">
                <button class="btn btn-primary" type="submit">Merge</button>
                <a class="btn" href="<?= View::text($back) ?>">Cancel</a>
            </div>
        </div>
    </form>
</div>
<?php endif; ?>
