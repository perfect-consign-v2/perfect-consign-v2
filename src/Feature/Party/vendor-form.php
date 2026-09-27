<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var array<string, mixed>|null $vendor */

$id = is_array($vendor) ? ($vendor['id'] ?? null) : null;
$code = is_array($vendor) ? ($vendor['code'] ?? '') : '';
$name = is_array($vendor) ? ($vendor['name'] ?? '') : '';
$editing = is_string($id) && $id !== '';
$action = $editing ? '/' . $slug . '/vendors/' . $id : '/' . $slug . '/vendors';
$heading = $editing ? 'Edit vendor' : 'New vendor';
$crumb = $editing && is_string($code) && $code !== '' ? $code : 'New';
$codeText = is_string($code) ? $code : '';
$nameText = is_string($name) ? $name : '';
?>
<div class="page-head">
    <div class="titles">
        <nav class="breadcrumb">
            <a href="/<?= View::text($slug) ?>/parties">Vendors</a><span class="sep">/</span>
            <span><?= View::text($crumb) ?></span>
        </nav>
        <h1 class="h1"><?= View::text($heading) ?></h1>
    </div>
</div>

<form method="post" action="<?= View::text($action) ?>" class="card form-sheet">
    <div class="card-body stack">
        <div class="field">
            <label for="code">Code</label>
            <input class="input" id="code" name="code" value="<?= View::text($codeText) ?>" required>
            <p class="muted text-sm">The code is how this mall finds the vendor.</p>
        </div>
        <div class="field">
            <label for="name">Name</label>
            <input class="input" id="name" name="name" value="<?= View::text($nameText) ?>" required>
            <p class="muted text-sm">The name is who gets paid.</p>
        </div>
        <div>
            <button class="btn btn-primary" type="submit"><svg aria-hidden="true"><use href="#i-check"></use></svg> <?= $editing ? 'Save changes' : 'Create vendor' ?></button>
        </div>
        <?php if (!$editing): ?>
            <p class="muted text-sm">After you create them, assign a booth.</p>
        <?php endif; ?>
    </div>
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
</form>

<?php if ($editing): ?>
    <div class="card form-sheet mt-6">
        <div class="card-body stack">
            <p class="muted text-sm">Delete is refused once this vendor has a booth assignment or a posted line.</p>
            <form method="post" action="/<?= View::text($slug) ?>/vendors/<?= View::text($id) ?>/delete">
                <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                <button class="btn btn-danger btn-sm" type="submit"><svg aria-hidden="true"><use href="#i-trash"></use></svg> Delete</button>
            </form>
        </div>
    </div>
<?php endif; ?>
