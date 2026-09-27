<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var array<string, mixed>|null $brand */

$id = is_array($brand) ? ($brand['id'] ?? null) : null;
$name = is_array($brand) ? ($brand['name'] ?? null) : null;
$editing = is_string($id) && $id !== '';
$action = $editing ? '/' . $slug . '/brands/' . $id : '/' . $slug . '/brands';
$heading = $editing ? 'Edit brand' : 'New brand';
$crumb = $editing && is_string($name) && $name !== '' ? $name : 'New';
?>
<div class="page-head">
    <div class="titles">
        <nav class="breadcrumb">
            <a href="/<?= View::text($slug) ?>/brands">Brands</a><span class="sep">/</span>
            <span><?= View::text($crumb) ?></span>
        </nav>
        <h1 class="h1"><?= View::text($heading) ?></h1>
    </div>
    <a class="btn" href="/<?= View::text($slug) ?>/brands"><svg aria-hidden="true"><use href="#i-arrow-left"></use></svg> Back</a>
</div>

<form method="post" action="<?= View::text($action) ?>" class="grid form-split" style="grid-template-columns: 2fr 1fr">
    <div class="card">
        <div class="card-head"><div class="h3">Brand details</div></div>
        <div class="card-body">
            <div class="field">
                <label for="name">Name</label>
                <input class="input" id="name" name="name" value="<?= View::text($brand['name'] ?? '') ?>" placeholder="e.g. Northwind" required>
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><div class="h3">Notes</div></div>
        <div class="card-body">
            <p class="muted text-sm">A brand is the name on the goods. Delete it only while no other row uses it. A second brand cannot use the same name.</p>
        </div>
        <div class="card-foot row between">
            <a class="btn btn-ghost" href="/<?= View::text($slug) ?>/brands">Cancel</a>
            <button class="btn btn-primary" type="submit"><svg aria-hidden="true"><use href="#i-check"></use></svg> <?= $editing ? 'Save changes' : 'Create brand' ?></button>
        </div>
    </div>
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
</form>
