<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var string $floorDays */
/** @var string $vendorsUrl */
/** @var string $brandsUrl */
/** @var string $brandCreateUrl */
/** @var array{id: string, code: string, barcode: string, description: string, price: string, vendor_id: string, vendor_label: string, brand_id: string, brand_label: string, store_take: string, default_take: string, overridden: bool, intake_on: string, expires_on: string, expire_dirty: bool} $piece */

$editing = $piece['id'] !== '';
$action = $editing ? '/' . $slug . '/inventory/' . $piece['id'] : '/' . $slug . '/inventory';
$heading = $editing ? 'Edit piece' : 'New piece';
$crumb = $editing && $piece['code'] !== '' ? $piece['code'] : 'New';
$expireHint = $floorDays === ''
    ? 'No days-on-the-floor default. Leave this empty and the piece does not expire.'
    : 'Defaults to ' . $floorDays . ' days after intake. Clear it and this piece does not expire.';
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

<?php if (!$editing && $piece['vendor_label'] !== ''): ?>
    <p class="muted">Adding pieces for <?= View::text($piece['vendor_label']) ?>.</p>
<?php endif; ?>

<form method="post" action="<?= View::text($action) ?>" id="piece-form" class="grid form-split" style="grid-template-columns: 2fr 1fr"
    data-vendors="<?= View::text($vendorsUrl) ?>"
    data-brands="<?= View::text($brandsUrl) ?>"
    data-brand-create="<?= View::text($brandCreateUrl) ?>"
    data-floor-days="<?= View::text($floorDays) ?>"
    data-default-take="<?= View::text($piece['default_take']) ?>"
    data-take-dirty="<?= $piece['overridden'] ? '1' : '0' ?>"
    data-expire-dirty="<?= $piece['expire_dirty'] ? '1' : '0' ?>">
    <div class="card">
        <div class="card-head"><div class="h3">Piece</div></div>
        <div class="card-body grid cols-2">
            <div class="field combo" style="grid-column: 1 / -1">
                <label for="vendor_label">Consignor</label>
                <input class="input" id="vendor_label" name="vendor_label" value="<?= View::text($piece['vendor_label']) ?>" autocomplete="off" required>
                <input type="hidden" id="vendor_id" name="vendor_id" value="<?= View::text($piece['vendor_id']) ?>">
                <div class="suggest" id="vendor_suggest" hidden></div>
                <p class="field-error" id="vendor_client_error" hidden>Choose a consignor from the list.</p>
            </div>
            <div class="field">
                <label for="code">Tag</label>
                <input class="input" id="code" name="code" value="<?= View::text($piece['code']) ?>" required>
            </div>
            <div class="field">
                <label for="barcode">Barcode</label>
                <input class="input" id="barcode" name="barcode" value="<?= View::text($piece['barcode']) ?>" maxlength="100">
                <p class="muted text-sm">Leave blank and a barcode is generated.</p>
            </div>
            <div class="field" style="grid-column: 1 / -1">
                <label for="description">Description</label>
                <input class="input" id="description" name="description" value="<?= View::text($piece['description']) ?>" required>
            </div>
            <div class="field combo" style="grid-column: 1 / -1">
                <label for="brand_label">Brand</label>
                <input class="input" id="brand_label" name="brand_label" value="<?= View::text($piece['brand_label']) ?>" autocomplete="off">
                <input type="hidden" id="brand_id" name="brand_id" value="<?= View::text($piece['brand_id']) ?>">
                <div class="suggest" id="brand_suggest" hidden></div>
                <div class="row gap-2" id="brand_create" hidden>
                    <span class="muted text-sm">Add brand <strong id="brand_create_name"></strong>?</span>
                    <button class="btn btn-sm" id="brand_create_button" type="button">Add brand</button>
                </div>
                <p class="field-error" id="brand_create_error" hidden></p>
            </div>
            <div class="field">
                <label for="price">Asking price</label>
                <input class="input" id="price" name="price" inputmode="decimal" value="<?= View::text($piece['price']) ?>" required>
            </div>
            <div class="field">
                <label for="store_take">Store take <span class="badge badge-warning" id="store_take_badge"<?= $piece['overridden'] ? '' : ' hidden' ?>>Overridden</span></label>
                <input class="input" id="store_take" name="store_take" inputmode="decimal" value="<?= View::text($piece['store_take']) ?>">
                <p class="muted text-sm">This consignor's split is <span id="store_take_default"><?= View::text($piece['default_take']) ?></span>%. A different amount is marked overridden.</p>
            </div>
            <div class="field">
                <label for="intake_on">Intake date</label>
                <input class="input" id="intake_on" name="intake_on" type="date" value="<?= View::text($piece['intake_on']) ?>" required>
            </div>
            <div class="field">
                <label for="expires_on">Expire date</label>
                <input class="input" id="expires_on" name="expires_on" type="date" value="<?= View::text($piece['expires_on']) ?>"<?= $piece['intake_on'] !== '' ? ' min="' . View::text($piece['intake_on']) . '"' : '' ?>>
                <p class="muted text-sm"><?= View::text($expireHint) ?></p>
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><div class="h3">Notes</div></div>
        <div class="card-body">
            <p class="muted text-sm">One piece. After you save, this form stays open for the same consignor. The asking price is not a sale. Delete the piece only while nothing else uses it.</p>
        </div>
        <div class="card-foot row between">
            <a class="btn btn-ghost" href="/<?= View::text($slug) ?>/inventory">Cancel</a>
            <button class="btn btn-primary" type="submit"><svg aria-hidden="true"><use href="#i-check"></use></svg> <?= $editing ? 'Save changes' : 'Create piece' ?></button>
        </div>
    </div>
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
</form>
<script src="/assets/js/inventory-intake.js" defer></script>
