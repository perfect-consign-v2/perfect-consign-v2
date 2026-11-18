<?php

declare(strict_types=1);

use PerfectConsign\Feature\Catalog\DeletePrompt;
use PerfectConsign\Feature\Catalog\NameList;
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
/** @var NameList $list */
/** @var list<array<string, mixed>> $rows */

$base = '/' . $slug . '/' . $list->path;
$renaming = $modal !== '' && $modal !== 'new';
$renameAction = $renaming ? $base . '/' . $modal : $base;
$noun = $list->singular;
$confirmMessage = DeletePrompt::sentence($noun);
$confirmAction = $base;
$keep = [];
if ($query !== '') {
    $keep['q'] = $query;
}
if ($standing === 'inactive') {
    $keep['status'] = 'inactive';
}
$back = $base . ($keep === [] ? '' : '?' . http_build_query($keep));
$label = strtolower($list->plural);
$empty = $query !== '' ? 'No ' . $label . ' match.' : ($standing === 'inactive' ? 'No inactive ' . $label . '.' : 'No active ' . $label . '.');
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1"><?= View::text($list->plural) ?></h1>
        <p class="muted">The <?= View::text($noun) ?> shoppers and cashiers search for.</p>
    </div>
    <button class="btn btn-primary" type="button" data-open-modal="name-new" data-brand-name=""><svg aria-hidden="true"><use href="#i-plus"></use></svg> New <?= View::text($noun) ?></button>
</div>

<?php if ($total === 0): ?>
    <div class="card">
        <div class="empty">
            <svg aria-hidden="true"><use href="#i-tag"></use></svg>
            <p>No <?= View::text(strtolower($list->plural)) ?> yet.</p>
        </div>
    </div>
<?php else: ?>
    <?php
    $listBase = $base;
    require $templateDirectory . '/Feature/Catalog/name-standing.php';
    ?>
    <div class="card table-wrap list-dt-wrap mt-4">
        <?php if ($rows === []): ?>
            <div class="empty"><p><?= View::text($empty) ?></p></div>
        <?php else: ?>
            <?php
            ListTable::open(['id' => $list->path, 'export' => $list->path]);
            ListTable::head([
                ['label' => 'Name'],
                ['label' => '', 'actions' => true],
            ]);
            ?>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $id = is_string($row['id'] ?? null) ? $row['id'] : '';
                    $name = is_string($row['name'] ?? null) ? $row['name'] : '';
                    $active = ($row['active'] ?? true) === true;
                    $deletable = ($row['deletable'] ?? false) === true;
                    $ask = $keep;
                    $ask['confirm'] = $id;
                    $delete = $base . '?' . http_build_query($ask);
                    $deleteAction = $base . '/' . $id . '/delete';
                    $mergeAsk = $keep;
                    $mergeAsk['merge'] = $id;
                    $mergeLink = $base . '?' . http_build_query($mergeAsk);
                    $rename = $base . '/' . $id;
                    if ($confirm === $id) {
                        $confirmAction = $deleteAction;
                    }
                    ?>
                    <tr id="<?= View::text($list->key) ?>-<?= View::text($id) ?>">
                        <td><?= View::text($name) ?><?php if (!$active): ?> <span class="muted">Inactive</span><?php endif; ?></td>
                        <td class="row gap-2">
                            <button class="btn btn-sm btn-ghost" type="button" data-open-modal="name-rename" data-brand-action="<?= View::text($rename) ?>" data-brand-name="<?= View::text($name) ?>"><svg aria-hidden="true"><use href="#i-edit"></use></svg> Rename</button>
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
                                <a class="btn btn-sm" href="<?= View::text($delete) ?>" data-open-modal="name-delete" data-brand-action="<?= View::text($deleteAction) ?>" data-delete-message="<?= View::text($confirmMessage) ?>"><svg aria-hidden="true"><use href="#i-trash"></use></svg> Delete</a>
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

<div id="name-new" class="modal" role="dialog" aria-modal="true" aria-labelledby="name-new-title"<?= $modal === 'new' ? '' : ' hidden' ?>>
    <form class="modal-panel card" method="post" action="<?= View::text($base) ?>">
        <div class="card-head">
            <?php $closeModal = 'name-new'; require $templateDirectory . '/Shared/Web/modal-x.php'; ?>
            <div class="h3" id="name-new-title">New <?= View::text($noun) ?></div>
        </div>
        <div class="card-body stack">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <label for="name-new-name">Name</label>
            <input class="input" id="name-new-name" name="name" value="<?= $modal === 'new' ? View::text($draft) : '' ?>" required>
            <div class="row gap-2">
                <button class="btn btn-primary" type="submit"><svg aria-hidden="true"><use href="#i-check"></use></svg> Create <?= View::text($noun) ?></button>
                <button class="btn" type="button" data-close-modal="name-new">Cancel</button>
            </div>
        </div>
    </form>
</div>

<div id="name-rename" class="modal" role="dialog" aria-modal="true" aria-labelledby="name-rename-title"<?= $renaming ? '' : ' hidden' ?>>
    <form class="modal-panel card" method="post" action="<?= View::text($renameAction) ?>">
        <div class="card-head">
            <?php $closeModal = 'name-rename'; require $templateDirectory . '/Shared/Web/modal-x.php'; ?>
            <div class="h3" id="name-rename-title">Rename <?= View::text($noun) ?></div>
        </div>
        <div class="card-body stack">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <?php if ($standing === 'inactive'): ?>
                <input type="hidden" name="status" value="inactive">
            <?php endif; ?>
            <label for="name-rename-name">Name</label>
            <input class="input" id="name-rename-name" name="name" value="<?= $renaming ? View::text($draft) : '' ?>" required>
            <div class="row gap-2">
                <button class="btn btn-primary" type="submit"><svg aria-hidden="true"><use href="#i-check"></use></svg> Rename <?= View::text($noun) ?></button>
                <button class="btn" type="button" data-close-modal="name-rename">Cancel</button>
            </div>
        </div>
    </form>
</div>

<div id="name-delete" class="modal" role="dialog" aria-modal="true" aria-labelledby="name-delete-title"<?= $confirm === '' ? ' hidden' : '' ?>>
    <form class="modal-panel card" method="post" action="<?= View::text($confirmAction) ?>">
        <div class="card-head">
            <?php $closeModal = 'name-delete'; require $templateDirectory . '/Shared/Web/modal-x.php'; ?>
            <div class="h3" id="name-delete-title">Delete <?= View::text($noun) ?></div>
        </div>
        <div class="card-body stack">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <?php if ($standing === 'inactive'): ?>
                <input type="hidden" name="status" value="inactive">
            <?php endif; ?>
            <p data-delete-prompt><?= View::text($confirmMessage) ?></p>
            <div class="row gap-2">
                <button class="btn btn-primary" type="submit">Delete</button>
                <button class="btn" type="button" data-close-modal="name-delete">Cancel</button>
            </div>
        </div>
    </form>
</div>

<?php if ($merge !== '' && $mergeChoices !== []): ?>
<div id="name-merge" class="modal" role="dialog" aria-modal="true" aria-labelledby="name-merge-title">
    <form class="modal-panel card" method="post" action="<?= View::text($base . '/' . $merge . '/merge') ?>">
        <div class="card-head">
            <?php $closeModal = 'name-merge'; require $templateDirectory . '/Shared/Web/modal-x.php'; ?>
            <div class="h3" id="name-merge-title">Merge <?= View::text($noun) ?></div>
        </div>
        <div class="card-body stack">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <p><?= View::text($mergeSentence) ?></p>
            <label for="name-merge-target">Keep</label>
            <select class="select" id="name-merge-target" name="target" required>
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
