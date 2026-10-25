<?php

declare(strict_types=1);

use PerfectConsign\Feature\Catalog\NameList;
use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var string $floorDays */
/** @var string $vendorsUrl */
/** @var string $brandsUrl */
/** @var string $brandCreateUrl */
/** @var bool $sold */
/** @var array{id: string, code: string, barcode: string, description: string, price: string, quantity: string, vendor_id: string, vendor_label: string, brand_id: string, brand_label: string, attributes: array<string, array{id: string, label: string}>, store_take: string, default_take: string, overridden: bool, intake_on: string, expires_on: string, expire_dirty: bool} $item */
/** @var list<NameList> $attributeLists */
/** @var string $labelStock */
/** @var string $printId */
/** @var list<array{key: string, label: string}> $stocks */

$sold = ($sold ?? false) === true;
$showBrand = ($showBrand ?? true) === true;
$photoRule = is_string($photoRule ?? null) && $photoRule !== '' ? $photoRule : 'JPEG, PNG, or WebP. 2 MB or smaller.';

$editing = $item['id'] !== '';
$action = $editing ? '/' . $slug . '/inventory/' . $item['id'] : '/' . $slug . '/inventory';
$heading = $editing ? 'Edit item' : 'New item';
$crumb = $editing && $item['code'] !== '' ? $item['code'] : 'New';
$expireHint = $floorDays === ''
    ? 'No days-on-the-floor default. Leave this empty and the item does not expire.'
    : 'Defaults to ' . $floorDays . ' days after intake. Clear it and this item does not expire.';
?>
<div class="page-head">
    <div class="titles">
        <nav class="breadcrumb">
            <a href="/<?= View::text($slug) ?>/inventory">Items</a><span class="sep">/</span>
            <span><?= View::text($crumb) ?></span>
        </nav>
        <h1 class="h1"><?= View::text($heading) ?></h1>
    </div>
    <a class="btn" href="/<?= View::text($slug) ?>/inventory"><svg aria-hidden="true"><use href="#i-arrow-left"></use></svg> Back</a>
</div>

<?php if (!$editing && $item['vendor_label'] !== ''): ?>
    <p class="muted">Adding items for <?= View::text($item['vendor_label']) ?>.</p>
<?php endif; ?>

<?php
$printId = $editing ? $item['id'] : $printId;
if ($printId !== '') {
    $lead = $editing ? 'Print stickers for this item.' : 'Print stickers for the item you just saved.';
    require __DIR__ . '/sticker-job.php';
}
?>

<form method="post" action="<?= View::text($action) ?>" id="item-form" class="vendor-edit" enctype="multipart/form-data"
    data-vendors="<?= View::text($vendorsUrl) ?>"
    data-brands="<?= View::text($brandsUrl) ?>"
    data-brand-create="<?= View::text($brandCreateUrl) ?>"
    data-floor-days="<?= View::text($floorDays) ?>"
    data-default-take="<?= View::text($item['default_take']) ?>"
    data-take-dirty="<?= $item['overridden'] ? '1' : '0' ?>"
    data-expire-dirty="<?= $item['expire_dirty'] ? '1' : '0' ?>"
    data-sold="<?= $sold ? '1' : '0' ?>">
    <div class="record-cards item-cards">
        <section class="card" aria-labelledby="item-identity">
            <div class="card-head"><div class="h3" id="item-identity">Item</div></div>
            <div class="card-body stack">
                <div class="field combo">
                    <label for="vendor_label">Consignor</label>
                    <input class="input" id="vendor_label" name="vendor_label" value="<?= View::text($item['vendor_label']) ?>" autocomplete="off" required<?= $sold ? ' readonly' : '' ?>>
                    <input type="hidden" id="vendor_id" name="vendor_id" value="<?= View::text($item['vendor_id']) ?>">
                    <div class="suggest" id="vendor_suggest" hidden></div>
                    <p class="field-error" id="vendor_client_error" hidden>Choose a consignor from the list.</p>
                    <?php if ($sold): ?><p class="muted text-sm">This consignor is on a sale and cannot be changed.</p><?php endif; ?>
                </div>
                <div class="vendor-pair">
                    <div class="field">
                        <label for="code">Tag</label>
                        <input class="input" id="code" name="code" value="<?= View::text($item['code']) ?>" required>
                    </div>
                    <div class="field">
                        <label for="barcode">Barcode</label>
                        <input class="input" id="barcode" name="barcode" value="<?= View::text($item['barcode']) ?>" maxlength="100">
                        <p class="muted text-sm">Leave blank and a barcode is generated.</p>
                    </div>
                </div>
                <div class="field">
                    <label for="description">Description</label>
                    <input class="input" id="description" name="description" value="<?= View::text($item['description']) ?>" required>
                </div>
            </div>
        </section>

        <section class="card" aria-labelledby="item-price">
            <div class="card-head"><div class="h3" id="item-price">Price</div></div>
            <div class="card-body stack">
                <div class="vendor-pair">
                    <div class="field">
                        <label for="price">Asking price</label>
                        <input class="input" id="price" name="price" inputmode="decimal" value="<?= View::text($item['price']) ?>" required<?= $sold ? ' readonly' : '' ?>>
                        <?php if ($sold): ?><p class="muted text-sm">This price is on a sale and cannot be changed.</p><?php endif; ?>
                    </div>
                    <div class="field">
                        <label for="quantity">Qty</label>
                        <input class="input" id="quantity" name="quantity" inputmode="numeric" value="<?= View::text($item['quantity']) ?>" required>
                    </div>
                </div>
                <div class="field">
                    <label for="store_take">Store take <span class="badge badge-warning" id="store_take_badge"<?= $item['overridden'] ? '' : ' hidden' ?>>Overridden</span></label>
                    <input class="input" id="store_take" name="store_take" inputmode="decimal" value="<?= View::text($item['store_take']) ?>">
                    <p class="muted text-sm">This consignor's split is <span id="store_take_default"><?= View::text($item['default_take']) ?></span>%. A different amount is marked overridden.</p>
                </div>
            </div>
        </section>

        <?php if ($showBrand || $attributeLists !== []): ?>
        <section class="card" aria-labelledby="item-details">
            <div class="card-head"><div class="h3" id="item-details">Details</div></div>
            <div class="card-body stack">
                <?php if ($showBrand): ?>
                <div class="field combo">
                    <label for="brand_label">Brand</label>
                    <input class="input" id="brand_label" name="brand_label" value="<?= View::text($item['brand_label']) ?>" autocomplete="off">
                    <input type="hidden" id="brand_id" name="brand_id" value="<?= View::text($item['brand_id']) ?>">
                    <div class="suggest" id="brand_suggest" hidden></div>
                    <div class="row gap-2" id="brand_create" hidden>
                        <span class="muted text-sm">Add brand <strong id="brand_create_name"></strong>?</span>
                        <button class="btn btn-sm" id="brand_create_button" type="button">Add brand</button>
                    </div>
                    <p class="field-error" id="brand_create_error" hidden></p>
                </div>
                <?php endif; ?>
                <?php foreach ($attributeLists as $attribute): ?>
                    <?php $field = $item['attributes'][$attribute->key] ?? ['id' => '', 'label' => '']; ?>
                    <div class="field combo" data-name-list data-search="/<?= View::text($slug) ?>/inventory/<?= View::text($attribute->path) ?>.json" data-create="/<?= View::text($slug) ?>/inventory/<?= View::text($attribute->path) ?>">
                        <label for="<?= View::text($attribute->key) ?>_label"><?= View::text(ucfirst($attribute->singular)) ?></label>
                        <input class="input" id="<?= View::text($attribute->key) ?>_label" name="<?= View::text($attribute->key) ?>_label" value="<?= View::text($field['label']) ?>" autocomplete="off">
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
        </section>
        <?php endif; ?>

        <section class="card" aria-labelledby="item-floor">
            <div class="card-head"><div class="h3" id="item-floor">Floor</div></div>
            <div class="card-body stack">
                <div class="field">
                    <label for="placement">Where it sits</label>
                    <select class="input" id="placement" name="placement">
                        <?php if (!empty($boothRentals)): ?>
                            <option value="booth"<?= ($item['placement'] ?? '') === 'booth' ? ' selected' : '' ?>>Booth</option>
                        <?php endif; ?>
                        <?php if (!empty($consignmentEnabled)): ?>
                            <option value="consigned"<?= ($item['placement'] ?? 'consigned') === 'consigned' ? ' selected' : '' ?>>Consigned</option>
                        <?php endif; ?>
                        <?php if (!empty($storeStockEnabled)): ?>
                            <option value="store"<?= ($item['placement'] ?? '') === 'store' ? ' selected' : '' ?>>Store</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="vendor-pair">
                    <div class="field">
                        <label for="intake_on">Intake date</label>
                        <input class="input" id="intake_on" name="intake_on" type="date" value="<?= View::text($item['intake_on']) ?>" required>
                    </div>
                    <?php if (!empty($consignmentEnabled)): ?>
                    <div class="field">
                        <label for="expires_on">Expire date</label>
                        <input class="input" id="expires_on" name="expires_on" type="date" value="<?= View::text($item['expires_on']) ?>"<?= $item['intake_on'] !== '' ? ' min="' . View::text($item['intake_on']) . '"' : '' ?>>
                        <p class="muted text-sm"><?= View::text($expireHint) ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <?php if (!$editing): ?>
        <section class="card" aria-labelledby="item-photo">
            <div class="card-head"><div class="h3" id="item-photo">Photo</div></div>
            <div class="card-body">
                <div class="field">
                    <label for="photo">Photo</label>
                    <input class="input" id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp">
                    <p class="muted text-sm">Optional. <?= View::text($photoRule) ?> It shows on this item and on the consignor's sale line.</p>
                </div>
            </div>
        </section>
        <?php endif; ?>
    </div>
    <div class="vendor-save">
        <button class="btn btn-primary" type="submit"><svg aria-hidden="true"><use href="#i-check"></use></svg> <?= $editing ? 'Save changes' : 'Create item' ?></button>
        <a class="btn btn-ghost" href="/<?= View::text($slug) ?>/inventory">Cancel</a>
    </div>
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
</form>
<?php if ($item['id'] !== ''): ?>
<?php $hasPhoto = ($hasPhoto ?? false) === true; ?>
<form class="card mt-6" method="post" action="/<?= View::text($slug) ?>/inventory/<?= View::text($item['id']) ?>/photo" enctype="multipart/form-data">
    <div class="card-head"><div class="h3">Photo</div></div>
    <div class="card-body stack">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <?php if ($hasPhoto): ?>
            <img src="/<?= View::text($slug) ?>/inventory/<?= View::text($item['id']) ?>/photo" alt="" width="160" height="160">
        <?php endif; ?>
        <div class="field">
            <label for="photo">One photo</label>
            <input class="input" id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp" required>
            <p class="muted text-sm"><?= View::text($photoRule) ?> Stored with this mall. It shows on this item and on the consignor's sale line.</p>
        </div>
    </div>
    <div class="card-foot">
        <button class="btn btn-primary" type="submit">Save photo</button>
    </div>
</form>
<?php endif; ?>
<script src="/assets/js/inventory-intake.js" defer></script>
