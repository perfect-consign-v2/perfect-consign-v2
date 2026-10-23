<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var list<array{id: string, name: string}> $vendors */
/** @var string|null $error */
/** @var string $vendorId */
/** @var string $itemCode */
/** @var string $description */
/** @var string $amount */
/** @var string $memo */
/** @var string $postingDate */
/** @var string $today */
/** @var string $formToken */
$memoValue = $memo !== '' ? $memo : 'Bought for the business, not for resale';
$dateValue = $postingDate !== '' ? $postingDate : $today;
?>
<h1>Buy for the store</h1>
<p class="muted">The store is buying this from the vendor for the business, not to resell. The vendor is paid the agreed price. No money goes in the drawer.</p>
<?php if ($error !== null && $error !== '') { ?>
    <p class="error"><?= View::text($error) ?></p>
<?php } ?>
<form method="post" action="/<?= View::text($slug) ?>/store-use">
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
    <input type="hidden" name="form_token" value="<?= View::text($formToken) ?>">
    <label for="vendor_id">Vendor</label>
    <select id="vendor_id" name="vendor_id" required>
        <option value="">Choose</option>
        <?php foreach ($vendors as $vendor) { ?>
            <option value="<?= View::text($vendor['id']) ?>"<?= $vendor['id'] === $vendorId ? ' selected' : '' ?>><?= View::text($vendor['name']) ?></option>
        <?php } ?>
    </select>
    <label for="item_code">Item code, if it was tagged</label>
    <input id="item_code" name="item_code" value="<?= View::text($itemCode) ?>">
    <label for="description">Piece</label>
    <input id="description" name="description" required maxlength="200" value="<?= View::text($description) ?>">
    <label for="amount">Agreed price</label>
    <input id="amount" name="amount" inputmode="decimal" required value="<?= View::text($amount) ?>">
    <label for="memo">What the store will do with it</label>
    <input id="memo" name="memo" required maxlength="200" value="<?= View::text($memoValue) ?>">
    <label for="posting_date">Posting date</label>
    <input id="posting_date" name="posting_date" type="date" value="<?= View::text($dateValue) ?>" required>
    <p><button type="submit">Post purchase</button></p>
</form>
