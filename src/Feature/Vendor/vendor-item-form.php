<?php

declare(strict_types=1);

use PerfectConsign\Feature\Vendor\VendorPreview;
use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var array{id: string, description: string, price: string, quantity: string, intake_on: string, expires_on: string, placement: string, brand_id: string, attributes: array<string, string>} $item */
/** @var list<string> $choices */
/** @var bool $brandOn */
/** @var list<array{id: string, name: string}> $brands */
/** @var list<array{key: string, singular: string, rows: list<array{id: string, name: string}>}> $attributes */
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
$brandOn = ($brandOn ?? false) === true;
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
<form method="post" action="<?= View::text($action) ?>" class="card mt-4">
    <div class="card-body stack">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <?php if (count($choices) > 1 && !$editing): ?>
            <fieldset class="field">
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
        <?php else: ?>
            <input type="hidden" name="placement" value="<?= View::text($item['placement']) ?>">
        <?php endif; ?>
        <div class="field">
            <label for="description">Description</label>
            <input class="input" id="description" name="description" value="<?= View::text($item['description']) ?>" required>
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
        <?php if ($brandOn): ?>
            <div class="field">
                <label for="brand_id">Brand</label>
                <select class="select" id="brand_id" name="brand_id">
                    <option value="">No brand</option>
                    <?php foreach ($brands as $brand): ?>
                        <option value="<?= View::text($brand['id']) ?>"<?= $brand['id'] === $item['brand_id'] ? ' selected' : '' ?>><?= View::text($brand['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <?php foreach ($attributes as $attribute): ?>
            <?php $selected = $item['attributes'][$attribute['key']] ?? ''; ?>
            <div class="field">
                <label for="<?= View::text($attribute['key']) ?>_id"><?= View::text(ucfirst($attribute['singular'])) ?></label>
                <select class="select" id="<?= View::text($attribute['key']) ?>_id" name="<?= View::text($attribute['key']) ?>_id">
                    <option value="">None</option>
                    <?php foreach ($attribute['rows'] as $row): ?>
                        <option value="<?= View::text($row['id']) ?>"<?= $row['id'] === $selected ? ' selected' : '' ?>><?= View::text($row['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="card-foot">
        <button class="btn btn-primary" type="submit"><?= $editing ? 'Save item' : 'Add item' ?></button>
    </div>
</form>
<?php if ($editing): ?>
    <form method="post" action="<?= View::text(VendorPreview::with('/' . $slug . '/vendor/inventory/' . $item['id'] . '/delete', $previewAs)) ?>" class="mt-4">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <button class="btn" type="submit">Delete item</button>
    </form>
<?php endif; ?>
<?php endif; ?>
