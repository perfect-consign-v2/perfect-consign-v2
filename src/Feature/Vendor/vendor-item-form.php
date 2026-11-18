<?php

declare(strict_types=1);

use PerfectConsign\Feature\Catalog\NameList;
use PerfectConsign\Feature\Vendor\VendorPreview;
use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var array{id: string, description: string, price: string, quantity: string, intake_on: string, expires_on: string, placement: string, brand_id: string, brand_label: string, attributes: array<string, array{id: string, label: string}>} $item */
/** @var list<string> $choices */
/** @var bool $showBrand */
/** @var list<NameList> $attributeLists */
/** @var string $floorDays */
/** @var string $labelStock */
/** @var string $printId */
/** @var list<array{key: string, label: string}> $stocks */
/** @var bool $sold */
/** @var bool $closed */

$previewAs = is_string($previewAs ?? null) ? $previewAs : '';
$editing = $item['id'] !== '';
$sold = ($sold ?? false) === true;
$closed = ($closed ?? false) === true;
$showBrand = ($showBrand ?? false) === true;
$attributeLists = is_array($attributeLists ?? null) ? $attributeLists : [];
$choices = $choices ?? [];
$printId = $printId ?? '';
$back = VendorPreview::with('/' . $slug . '/vendor/inventory', $previewAs);
$action = $editing
    ? VendorPreview::with('/' . $slug . '/vendor/inventory/' . $item['id'], $previewAs)
    : VendorPreview::with('/' . $slug . '/vendor/inventory/new', $previewAs);
$showExpire = $item['placement'] === 'consigned' || (!$editing && in_array('consigned', $choices, true));
$expireHint = $floorDays === ''
    ? 'Leave this empty and the piece does not expire.'
    : 'A new consigned piece starts at ' . $floorDays . ' days after intake. Clear it and the piece does not expire.';
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1"><?= $editing ? 'Edit item' : 'New item' ?></h1>
    </div>
    <a class="btn" href="<?= View::text($back) ?>">Back to items</a>
</div>

<?php if ($printId !== ''): ?>
    <?php
    $lead = 'Print stickers for the item you just saved.';
    $labelAction = VendorPreview::with('/' . $slug . '/vendor/labels', $previewAs);
    require __DIR__ . '/../Inventory/sticker-job.php';
    ?>
<?php endif; ?>

<?php if ($closed): ?>
    <div class="card">
        <div class="card-body">
            <p>This store is not taking vendor items.</p>
        </div>
    </div>
<?php else: ?>
<form method="post" action="<?= View::text($action) ?>" class="vendor-edit">
    <div class="card">
    <div class="card-body">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <?php if (count($choices) <= 1 || $editing): ?>
            <input type="hidden" name="placement" value="<?= View::text($item['placement']) ?>">
        <?php endif; ?>
        <div class="vendor-pair">
            <?php if (count($choices) > 1 && !$editing): ?>
                <fieldset class="field span-2 choice-row">
                    <legend>Where it sits</legend>
                    <label for="placement-booth">
                        <input id="placement-booth" type="radio" name="placement" value="booth" required<?= $item['placement'] === 'booth' ? ' checked' : '' ?>>
                        Booth
                    </label>
                    <label for="placement-consigned">
                        <input id="placement-consigned" type="radio" name="placement" value="consigned" required<?= $item['placement'] === 'consigned' ? ' checked' : '' ?>>
                        Consignment
                    </label>
                </fieldset>
            <?php endif; ?>
            <div class="field">
                <label for="description">Description</label>
                <input class="input" id="description" name="description" value="<?= View::text($item['description']) ?>" required autofocus>
            </div>
            <div class="field">
                <label for="price">Asking price</label>
                <input class="input" id="price" name="price" inputmode="decimal" value="<?= View::text($item['price']) ?>" required<?= $sold ? ' readonly' : '' ?>>
                <?php if ($sold): ?><p class="muted text-sm">This price is on a sale and cannot be changed.</p><?php endif; ?>
            </div>
            <div class="field">
                <label for="quantity">Quantity</label>
                <input class="input" id="quantity" name="quantity" inputmode="numeric" value="<?= View::text($item['quantity']) ?>" required>
                <p class="muted text-sm"><?= $editing
                    ? 'Quantity zero pulls the piece. It does not record a sale.'
                    : 'At least 1.' ?></p>
            </div>
            <div class="field">
                <label for="intake_on">Intake date</label>
                <input class="input" id="intake_on" name="intake_on" type="date" value="<?= View::text($item['intake_on']) ?>" required>
            </div>
            <?php if ($showExpire): ?>
                <div class="field">
                    <label for="expires_on">Expire date</label>
                    <input class="input" id="expires_on" name="expires_on" type="date" value="<?= View::text($item['expires_on']) ?>" min="<?= View::text($item['intake_on']) ?>">
                    <p class="muted text-sm"><?= View::text($expireHint) ?> A booth piece does not expire.</p>
                </div>
            <?php endif; ?>
            <?php if ($showBrand): ?>
                <div class="field combo" data-name-list data-search="/<?= View::text($slug) ?>/inventory/brands.json" data-create="/<?= View::text($slug) ?>/inventory/brands">
                    <label for="brand_label">Brand</label>
                    <input class="input" id="brand_label" name="brand_label" value="<?= View::text($item['brand_label']) ?>" autocomplete="off" placeholder="Search or type a new one">
                    <input type="hidden" id="brand_id" name="brand_id" value="<?= View::text($item['brand_id']) ?>">
                    <div class="suggest" hidden></div>
                    <div class="row gap-2" hidden data-create-row>
                        <span class="muted text-sm">Add brand <strong data-create-name></strong>?</span>
                        <button class="btn btn-sm" type="button" data-create-button>Add brand</button>
                    </div>
                    <p class="field-error" hidden></p>
                </div>
            <?php endif; ?>
            <?php foreach ($attributeLists as $attribute): ?>
                <?php $field = $item['attributes'][$attribute->key] ?? ['id' => '', 'label' => '']; ?>
                <div class="field combo" data-name-list data-search="/<?= View::text($slug) ?>/inventory/<?= View::text($attribute->path) ?>.json" data-create="/<?= View::text($slug) ?>/inventory/<?= View::text($attribute->path) ?>">
                    <label for="<?= View::text($attribute->key) ?>_label"><?= View::text(ucfirst($attribute->singular)) ?></label>
                    <input class="input" id="<?= View::text($attribute->key) ?>_label" name="<?= View::text($attribute->key) ?>_label" value="<?= View::text($field['label']) ?>" autocomplete="off" placeholder="Search or type a new one">
                    <input type="hidden" id="<?= View::text($attribute->key) ?>_id" name="<?= View::text($attribute->key) ?>_id" value="<?= View::text($field['id']) ?>">
                    <div class="suggest" hidden></div>
                    <div class="row gap-2" hidden data-create-row>
                        <span class="muted text-sm">Add <?= View::text($attribute->singular) ?> <strong data-create-name></strong>?</span>
                        <button class="btn btn-sm" type="button" data-create-button>Add <?= View::text($attribute->singular) ?></button>
                    </div>
                    <p class="field-error" hidden></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="card-foot">
        <button class="btn btn-primary" type="submit"><?= $editing ? 'Save item' : 'Add item' ?></button>
    </div>
    </div>
</form>
<script src="<?= View::asset('/assets/js/name-list.js') ?>" defer></script>
<?php if ($editing): ?>
    <form method="post" action="<?= View::text(VendorPreview::with('/' . $slug . '/vendor/inventory/' . $item['id'] . '/delete', $previewAs)) ?>" class="mt-4">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <button class="btn" type="submit">Delete item</button>
    </form>
<?php endif; ?>
<?php endif; ?>
