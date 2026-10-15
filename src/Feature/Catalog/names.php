<?php

declare(strict_types=1);

use PerfectConsign\Feature\Catalog\NameList;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Web\ListTable;

/** @var string $slug */
/** @var string $csrf */
/** @var string $query */
/** @var int $total */
/** @var string $confirm */
/** @var string $modal */
/** @var string $draft */
/** @var NameList $list */
/** @var list<array<string, mixed>> $rows */

$base = '/' . $slug . '/' . $list->path;
$renaming = $modal !== '' && $modal !== 'new';
$renameAction = $renaming ? $base . '/' . $modal : $base;
$noun = $list->singular;
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
    <form class="row gap-2 mt-6" method="get" action="<?= View::text($base) ?>" role="search">
        <input class="input" style="max-width:20rem" type="search" name="q" value="<?= View::text($query) ?>" placeholder="Search name" aria-label="Search <?= View::text(strtolower($list->plural)) ?>">
        <button class="btn" type="submit">Search</button>
    </form>

    <div class="card table-wrap list-dt-wrap mt-4">
        <?php if ($rows === []): ?>
            <div class="empty"><p>No <?= View::text(strtolower($list->plural)) ?> match.</p></div>
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
                    $deletable = ($row['deletable'] ?? false) === true;
                    $keep = $query !== '' ? ['q' => $query] : [];
                    $cancel = $base . ($keep === [] ? '' : '?' . http_build_query($keep));
                    $ask = $keep;
                    $ask['confirm'] = $id;
                    $delete = $base . '?' . http_build_query($ask);
                    $rename = $base . '/' . $id;
                    ?>
                    <tr id="<?= View::text($list->key) ?>-<?= View::text($id) ?>">
                        <td><?= View::text($name) ?></td>
                        <td class="row gap-2">
                            <button class="btn btn-sm btn-ghost" type="button" data-open-modal="name-rename" data-brand-action="<?= View::text($rename) ?>" data-brand-name="<?= View::text($name) ?>"><svg aria-hidden="true"><use href="#i-edit"></use></svg> Rename</button>
                            <?php if ($confirm === $id): ?>
                                <span>Delete this <?= View::text($noun) ?>?</span>
                                <a class="btn btn-sm btn-ghost" href="<?= View::text($cancel) ?>">Cancel</a>
                                <form method="post" action="<?= View::text($base) ?>/<?= View::text($id) ?>/delete">
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
    <?php ListTable::assets(); ?>
<?php endif; ?>

<div id="name-new" class="modal" role="dialog" aria-modal="true" aria-labelledby="name-new-title"<?= $modal === 'new' ? '' : ' hidden' ?>>
    <form class="modal-panel card" method="post" action="<?= View::text($base) ?>">
        <div class="card-head"><div class="h3" id="name-new-title">New <?= View::text($noun) ?></div></div>
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
        <div class="card-head"><div class="h3" id="name-rename-title">Rename <?= View::text($noun) ?></div></div>
        <div class="card-body stack">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <label for="name-rename-name">Name</label>
            <input class="input" id="name-rename-name" name="name" value="<?= $renaming ? View::text($draft) : '' ?>" required>
            <div class="row gap-2">
                <button class="btn btn-primary" type="submit"><svg aria-hidden="true"><use href="#i-check"></use></svg> Rename <?= View::text($noun) ?></button>
                <button class="btn" type="button" data-close-modal="name-rename">Cancel</button>
            </div>
        </div>
    </form>
</div>
