<?php

declare(strict_types=1);

use PerfectConsign\Feature\Vendor\VendorPreview;
use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var string $base */
/** @var string $id */
/** @var bool $locked */
/** @var string|null $error */
/** @var array{name: string, scope: string, kind: string, threshold: string, price: string, funded_by: string, starts_on: string, ends_on: string, item_code: string, vendor_id: string} $draft */
/** @var list<array{id: string, code: string, name: string}> $vendors */

$action = $id === '' ? $base : $base . '/' . $id;
$previewAs = is_string($previewAs ?? null) ? $previewAs : '';
$action = VendorPreview::with($action, $previewAs);
$back = VendorPreview::with($base, $previewAs);
$each = $draft['kind'] === 'unit_break';
$seller = ($consignmentOnly ?? false) === true ? 'consignor' : 'vendor';
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1"><?= $id === '' ? 'New deal' : 'Edit deal' ?></h1>
        <p class="muted">Buy a bunch for one price, or a lower price each once they buy enough. The sticker price does not change.</p>
    </div>
    <a class="btn" href="<?= View::text($back) ?>">Back</a>
</div>
<form class="card mt-6" method="post" action="<?= View::text($action) ?>">
    <div class="card-body stack">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <label for="name">Name</label>
        <input class="input" id="name" name="name" value="<?= View::text($draft['name']) ?>" required maxlength="80">
        <label for="scope">Applies to</label>
        <select class="input" id="scope" name="scope">
            <option value="item"<?= $draft['scope'] === 'item' ? ' selected' : '' ?>><?= $locked ? 'One of my items' : 'One item' ?></option>
            <option value="vendor"<?= $draft['scope'] === 'vendor' ? ' selected' : '' ?>><?= $locked ? 'All my items' : ((($consignmentOnly ?? false) === true) ? 'One consignor' : 'One vendor') ?></option>
            <?php if (!$locked): ?>
                <option value="store"<?= $draft['scope'] === 'store' ? ' selected' : '' ?>>Whole store</option>
            <?php endif; ?>
        </select>
        <?php if ($locked): ?>
            <?php $itemSearch = VendorPreview::with('/' . $slug . '/vendor/offers/items.json', $previewAs); ?>
            <div class="combo" id="item-lookup" data-item-lookup data-search="<?= View::text($itemSearch) ?>"<?= $draft['scope'] === 'item' ? '' : ' hidden' ?>>
                <label for="item_label">Item</label>
                <input class="input" id="item_label" value="<?= View::text(is_string($itemLabel ?? null) ? $itemLabel : '') ?>" autocomplete="off" placeholder="Search tag, barcode, or description">
                <input type="hidden" id="item_code" name="item_code" value="<?= View::text($draft['item_code']) ?>">
                <div class="suggest" hidden></div>
            </div>
        <?php else: ?>
            <label for="item_code">Item tag</label>
            <input class="input" id="item_code" name="item_code" value="<?= View::text($draft['item_code']) ?>" autocomplete="off">
        <?php endif; ?>
        <?php if (!$locked): ?>
            <label for="vendor_id"><?= ($consignmentOnly ?? false) === true ? 'Consignor' : 'Vendor' ?></label>
            <select class="input" id="vendor_id" name="vendor_id">
                <option value="">Choose</option>
                <?php foreach ($vendors as $vendor): ?>
                    <option value="<?= View::text($vendor['id']) ?>"<?= $draft['vendor_id'] === $vendor['id'] ? ' selected' : '' ?>><?= View::text($vendor['code'] . ' ' . $vendor['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <label for="funded_by">The check</label>
            <select class="input" id="funded_by" name="funded_by">
                <option value="vendor"<?= $draft['funded_by'] !== 'store' ? ' selected' : '' ?>>The <?= View::text($seller) ?> is paid on the sale price</option>
                <option value="store"<?= $draft['funded_by'] === 'store' ? ' selected' : '' ?>>The <?= View::text($seller) ?> is paid on the tag price. The store covers the difference.</option>
            </select>
            <p class="muted">A whole-store sale is usually paid on the tag price.</p>
        <?php else: ?>
            <input type="hidden" name="funded_by" value="vendor">
            <input type="hidden" name="vendor_id" value="">
        <?php endif; ?>
        <label for="kind">The sale</label>
        <select class="input" id="kind" name="kind">
            <option value="bundle"<?= $each ? '' : ' selected' ?>>Buy this many for one price</option>
            <option value="unit_break"<?= $each ? ' selected' : '' ?>>This many or more, at a lower price each</option>
        </select>
        <p class="muted" id="kind-hint"><?= $each ? 'Once they buy that many, each one rings at this price.' : 'One or two still ring at the tag price.' ?></p>
        <label for="threshold">How many</label>
        <input class="input" id="threshold" name="threshold" inputmode="numeric" value="<?= View::text($draft['threshold']) ?>" required>
        <label for="price" id="price-label"><?= $each ? 'Price each' : 'Price for the bunch' ?></label>
        <input class="input" id="price" name="price" inputmode="decimal" value="<?= View::text($draft['price']) ?>" required>
        <label for="starts_on">Starts</label>
        <input class="input" id="starts_on" name="starts_on" type="date" value="<?= View::text($draft['starts_on']) ?>" required>
        <label for="ends_on">Ends</label>
        <input class="input" id="ends_on" name="ends_on" type="date" value="<?= View::text($draft['ends_on']) ?>">
        <button class="btn btn-primary" type="submit">Save deal</button>
    </div>
</form>
<script src="<?= View::asset('/assets/js/offer-form.js') ?>" defer></script>
