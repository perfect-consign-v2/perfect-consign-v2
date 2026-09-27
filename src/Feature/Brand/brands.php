<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var list<array<string, mixed>> $brands */
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Brands</h1>
        <p class="muted">The name shoppers and cashiers search for.</p>
    </div>
    <a class="btn btn-primary" href="/<?= View::text($slug) ?>/brands/new"><svg aria-hidden="true"><use href="#i-plus"></use></svg> New brand</a>
</div>

<?php if ($brands === []): ?>
    <div class="card">
        <div class="empty">
            <svg aria-hidden="true"><use href="#i-tag"></use></svg>
            <p>No brands yet.</p>
        </div>
    </div>
<?php else: ?>
    <div class="grid cols-2">
        <?php foreach ($brands as $brand): ?>
            <article class="card" id="brand-<?= View::text($brand['id']) ?>">
                <div class="card-head">
                    <div class="h3"><?= View::text($brand['name']) ?></div>
                </div>
                <div class="card-foot row between">
                    <a class="btn btn-sm btn-ghost" href="/<?= View::text($slug) ?>/brands/<?= View::text($brand['id']) ?>/edit"><svg aria-hidden="true"><use href="#i-edit"></use></svg> Rename</a>
                    <form method="post" action="/<?= View::text($slug) ?>/brands/<?= View::text($brand['id']) ?>/delete">
                        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                        <button class="btn btn-sm" type="submit"><svg aria-hidden="true"><use href="#i-trash"></use></svg> Delete</button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
