<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var list<array<string, mixed>> $booths */
/** @var int $defaultWidth */
/** @var int $defaultDepth */
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Booths</h1>
        <p class="muted">A code cashiers say, a name, and a size in feet. Empty size uses the mall default, <?= View::text($defaultWidth) ?> by <?= View::text($defaultDepth) ?>.</p>
    </div>
    <a class="btn btn-primary" href="/<?= View::text($slug) ?>/booths/new"><svg aria-hidden="true"><use href="#i-plus"></use></svg> New booth</a>
</div>

<?php if ($booths === []): ?>
    <div class="card">
        <div class="empty">
            <svg aria-hidden="true"><use href="#i-booth"></use></svg>
            <p>No booths yet.</p>
        </div>
    </div>
<?php else: ?>
    <div class="grid cols-2">
        <?php foreach ($booths as $booth): ?>
            <?php
            $width = $booth['width_ft'];
            $depth = $booth['depth_ft'];
            $ownSize = $width !== null && $width !== '' && $depth !== null && $depth !== '';
            ?>
            <article class="card" id="booth-<?= View::text($booth['id']) ?>">
                <div class="card-head">
                    <div class="h3"><?= View::text($booth['name']) ?></div>
                    <span class="badge badge-accent"><?= View::text($booth['code']) ?></span>
                </div>
                <div class="card-body">
                    <div class="row between">
                        <span class="muted">Size</span>
                        <span class="strong"><?= $ownSize ? View::text($width) . ' × ' . View::text($depth) . ' ft' : View::text($defaultWidth) . ' × ' . View::text($defaultDepth) . ' ft' ?></span>
                    </div>
                </div>
                <div class="card-foot row between">
                    <a class="btn btn-sm btn-ghost" href="/<?= View::text($slug) ?>/booths/<?= View::text($booth['id']) ?>/edit"><svg aria-hidden="true"><use href="#i-edit"></use></svg> Edit</a>
                    <form method="post" action="/<?= View::text($slug) ?>/booths/<?= View::text($booth['id']) ?>/delete">
                        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                        <button class="btn btn-sm" type="submit"><svg aria-hidden="true"><use href="#i-trash"></use></svg> Delete</button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
