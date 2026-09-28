<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var array<string, mixed>|null $item */
/** @var list<array<string, mixed>> $vendors */
/** @var list<array<string, mixed>> $brands */

$id = is_array($item) ? ($item['id'] ?? null) : null;
$code = is_array($item) ? ($item['code'] ?? null) : null;
$editing = is_string($id) && $id !== '';
$action = $editing ? '/' . $slug . '/inventory/' . $id : '/' . $slug . '/inventory';
$heading = $editing ? 'Edit piece' : 'New piece';
$crumb = $editing && is_string($code) && $code !== '' ? $code : 'New';
$vendorId = is_array($item) && is_string($item['vendor_id'] ?? null) ? $item['vendor_id'] : '';
$brandId = is_array($item) && is_string($item['brand_id'] ?? null) ? $item['brand_id'] : '';
$price = '';
if (is_array($item) && is_string($item['price'] ?? null)) {
    $price = $item['price'];
} elseif (is_array($item) && isset($item['price_minor'])) {
    $price = View::money(View::minor($item['price_minor']));
}
?>
<div class="page-head">
    <div class="titles">
        <nav class="breadcrumb">
            <a href="/<?= View::text($slug) ?>/inventory">Inventory</a><span class="sep">/</span>
            <span><?= View::text($crumb) ?></span>
        </nav>
        <h1 class="h1"><?= View::text($heading) ?></h1>
    </div>
    <a class="btn" href="/<?= View::text($slug) ?>/inventory"><svg aria-hidden="true"><use href="#i-arrow-left"></use></svg> Back</a>
</div>

<form method="post" action="<?= View::text($action) ?>" class="grid form-split" style="grid-template-columns: 2fr 1fr">
    <div class="card">
        <div class="card-head"><div class="h3">Piece</div></div>
        <div class="card-body grid cols-2">
            <div class="field">
                <label for="code">Tag</label>
                <input class="input" id="code" name="code" value="<?= View::text(is_array($item) ? ($item['code'] ?? '') : '') ?>" required>
            </div>
            <div class="field">
                <label for="price">Asking price</label>
                <input class="input" id="price" name="price" inputmode="decimal" value="<?= View::text($price) ?>" required>
            </div>
            <div class="field">
                <label for="vendor_id">Vendor</label>
                <select class="select" id="vendor_id" name="vendor_id" required>
                    <option value="">Choose a vendor</option>
                    <?php foreach ($vendors as $vendor): ?>
                        <?php
                        $optionId = is_string($vendor['id'] ?? null) ? $vendor['id'] : '';
                        $optionName = is_string($vendor['name'] ?? null) ? $vendor['name'] : '';
                        ?>
                        <option value="<?= View::text($optionId) ?>"<?= $optionId === $vendorId ? ' selected' : '' ?>><?= View::text($optionName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="brand_id">Brand</label>
                <select class="select" id="brand_id" name="brand_id">
                    <option value="">No brand</option>
                    <?php foreach ($brands as $brand): ?>
                        <?php
                        $optionId = is_string($brand['id'] ?? null) ? $brand['id'] : '';
                        $optionName = is_string($brand['name'] ?? null) ? $brand['name'] : '';
                        ?>
                        <option value="<?= View::text($optionId) ?>"<?= $optionId === $brandId ? ' selected' : '' ?>><?= View::text($optionName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" style="grid-column: 1 / -1">
                <label for="description">Description</label>
                <input class="input" id="description" name="description" value="<?= View::text(is_array($item) ? ($item['description'] ?? '') : '') ?>" required>
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><div class="h3">Notes</div></div>
        <div class="card-body">
            <p class="muted text-sm">One piece. The asking price is not a sale. Delete it only while nothing else uses it. An inactive vendor can still own a piece.</p>
        </div>
        <div class="card-foot row between">
            <a class="btn btn-ghost" href="/<?= View::text($slug) ?>/inventory">Cancel</a>
            <button class="btn btn-primary" type="submit"><svg aria-hidden="true"><use href="#i-check"></use></svg> <?= $editing ? 'Save changes' : 'Create piece' ?></button>
        </div>
    </div>
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
</form>
