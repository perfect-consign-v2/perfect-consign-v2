<?php

declare(strict_types=1);

use PerfectConsign\Feature\Catalog\NameList;
use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var bool $openInstant */
/** @var bool $showBrand */
/** @var list<NameList> $attributeLists */
/** @var mixed $consignmentOnly */

$instantError = is_string($instantError ?? null) ? $instantError : '';
$instantVendorId = is_string($instantVendorId ?? null) ? $instantVendorId : '';
$instantVendorLabel = is_string($instantVendorLabel ?? null) ? $instantVendorLabel : '';
$instantDescription = is_string($instantDescription ?? null) ? $instantDescription : '';
$instantPrice = is_string($instantPrice ?? null) ? $instantPrice : '';
$instantDiscountKind = is_string($instantDiscountKind ?? null) ? $instantDiscountKind : 'consignor_default';
$instantDiscountValue = is_string($instantDiscountValue ?? null) ? $instantDiscountValue : '';
$instantBrandId = is_string($instantBrandId ?? null) ? $instantBrandId : '';
$instantBrandLabel = is_string($instantBrandLabel ?? null) ? $instantBrandLabel : '';
$instantAttributes = is_array($instantAttributes ?? null) ? $instantAttributes : [];
$instantPriceError = in_array($instantError, ['Enter a price.', 'enter an amount in dollars and cents', 'the amount is too large', 'the amount must be greater than zero'], true);
$instantAdded = ($instantAdded ?? false) === true || ($instantAddedPage ?? false) === true;
?>
<div id="instant-add" class="modal" role="dialog" aria-modal="true" aria-labelledby="instant-add-title"<?= $openInstant ? '' : ' hidden' ?>>
    <form id="instant-form" class="modal-panel card register-instant" method="post" action="/<?= View::text($slug) ?>/pos/instant" data-vendors="/<?= View::text($slug) ?>/inventory/vendors.json" data-brands="/<?= View::text($slug) ?>/inventory/brands.json" data-brand-create="/<?= View::text($slug) ?>/inventory/brands">
        <div class="card-head">
            <div class="h3" id="instant-add-title">Instant inventory</div>
            <button class="btn" type="button" data-close-modal="instant-add">Close</button>
        </div>
        <div class="card-body stack">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <input type="hidden" id="instant_draft_no" name="draft_no" value="">
            <p class="muted text-sm">Leave a field blank to put a MISSING line on the ticket. Finish those lines before pay.</p>
            <p class="error" id="instant-error" role="alert"<?= $instantError === '' ? ' hidden' : '' ?>><?= View::text($instantError) ?></p>
            <?php if ($instantAdded): ?>
                <p class="alert alert-success" role="status">Item added to cart.</p>
            <?php endif; ?>
            <div class="field combo">
                <label for="instant_vendor">Consignor</label>
                <input class="input" id="instant_vendor" name="vendor_label" value="<?= View::text($instantVendorLabel) ?>" autocomplete="off" placeholder="<?= !empty($consignmentOnly) ? 'Name or consignor number' : 'Name or vendor number' ?>"<?= ($openInstant && !$instantPriceError) ? ' autofocus' : '' ?>>
                <input type="hidden" id="instant_vendor_id" name="vendor_id" value="<?= View::text($instantVendorId) ?>">
                <div class="suggest" id="instant-vendor-suggest" hidden></div>
                <p class="muted text-sm" id="instant-vendor-hint" hidden></p>
                <p class="field-error" id="instant-vendor-error" hidden></p>
            </div>
            <div class="field">
                <label for="instant_description">Description</label>
                <input class="input" id="instant_description" name="description" value="<?= View::text($instantDescription) ?>" placeholder="Short description for tags and receipt">
            </div>
            <div class="field">
                <label for="instant_price">Price</label>
                <input class="input" id="instant_price" name="price" value="<?= View::text($instantPrice) ?>" inputmode="decimal"<?= $instantPriceError ? ' aria-invalid="true" aria-describedby="instant-error" autofocus' : '' ?>>
            </div>
            <div class="field">
                <label for="instant_discount_kind">Line discount</label>
                <div class="register-line">
                    <select class="input" id="instant_discount_kind" name="discount_kind" aria-label="Line discount type">
                        <?php foreach (['consignor_default' => 'Consignor default', 'none' => 'None', 'percent' => '%', 'flat' => '$'] as $discountKind => $discountLabel): ?>
                            <option value="<?= View::text($discountKind) ?>"<?= $instantDiscountKind === $discountKind ? ' selected' : '' ?>><?= View::text($discountLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input class="input" id="instant_discount_value" name="discount_value" value="<?= View::text($instantDiscountValue) ?>" inputmode="decimal" placeholder="0" aria-label="Line discount value" hidden disabled>
                </div>
                <p class="muted text-sm"><?= !empty($consignmentOnly)
                    ? 'Consignor default applies the consignor’s default POS line discount when set. Choose None for no discount, or enter a percent or flat amount.'
                    : 'Consignor default applies the vendor’s default POS line discount when set. Choose None for no discount, or enter a percent or flat amount.' ?></p>
            </div>
            <?php if ($showBrand): ?>
            <div class="field combo">
                <label for="instant_brand">Brand</label>
                <input class="input" id="instant_brand" name="brand_label" value="<?= View::text($instantBrandLabel) ?>" autocomplete="off" placeholder="Search brands or type a new one">
                <input type="hidden" id="instant_brand_id" name="brand_id" value="<?= View::text($instantBrandId) ?>">
                <div class="suggest" id="instant-brand-suggest" hidden></div>
                <div class="row gap-2" id="instant-brand-create" hidden>
                    <span class="muted text-sm">Add brand <strong id="instant-brand-create-name"></strong>?</span>
                    <button class="btn btn-sm" id="instant-brand-create-button" type="button">Add brand</button>
                </div>
                <p class="field-error" id="instant-brand-error" hidden></p>
            </div>
            <?php endif; ?>
            <?php foreach ($attributeLists as $attribute): ?>
                <?php
                $savedAttribute = $instantAttributes[$attribute->key] ?? ['id' => '', 'label' => ''];
                $attributeId = is_array($savedAttribute) && is_string($savedAttribute['id'] ?? null) ? $savedAttribute['id'] : '';
                $attributeLabel = is_array($savedAttribute) && is_string($savedAttribute['label'] ?? null) ? $savedAttribute['label'] : '';
                ?>
                <div class="field combo" data-name-list data-search="/<?= View::text($slug) ?>/inventory/<?= View::text($attribute->path) ?>.json" data-create="/<?= View::text($slug) ?>/inventory/<?= View::text($attribute->path) ?>">
                    <label for="instant_<?= View::text($attribute->key) ?>"><?= View::text(ucfirst($attribute->singular)) ?></label>
                    <input class="input" id="instant_<?= View::text($attribute->key) ?>" name="<?= View::text($attribute->key) ?>_label" value="<?= View::text($attributeLabel) ?>" autocomplete="off">
                    <input type="hidden" name="<?= View::text($attribute->key) ?>_id" value="<?= View::text($attributeId) ?>">
                    <div class="suggest" hidden></div>
                    <div class="row gap-2" hidden data-create-row>
                        <span class="muted text-sm">Add <?= View::text($attribute->singular) ?> <strong data-create-name></strong>?</span>
                        <button class="btn btn-sm" type="button" data-create-button>Add <?= View::text($attribute->singular) ?></button>
                    </div>
                    <p class="field-error" hidden></p>
                </div>
            <?php endforeach; ?>
            <div class="row gap-2">
                <button class="btn btn-primary" type="submit" name="instant_action" value="save_and_new">Save and New</button>
                <button class="btn" type="submit" name="instant_action" value="save_and_close">Save &amp; Close</button>
            </div>
        </div>
    </form>
</div>
