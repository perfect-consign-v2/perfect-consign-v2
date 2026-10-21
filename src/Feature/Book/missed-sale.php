<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var list<array{id: string, name: string}> $vendors */
/** @var string|null $error */
/** @var string|null $share */
/** @var string $vendorId */
/** @var string $itemCode */
/** @var string $description */
/** @var string $price */
/** @var string $memo */
/** @var string $postingDate */
/** @var string $today */
$memoValue = $memo !== '' ? $memo : 'The sale was never rung';
$dateValue = $postingDate !== '' ? $postingDate : $today;
?>
<h1>Missed sale</h1>
<p class="muted">The customer took a piece that was never rung up. The vendor is paid their usual share. The store records that share as a loss. No money goes in the drawer.</p>
<?php if ($error !== null && $error !== '') { ?>
    <p class="error"><?= View::text($error) ?></p>
<?php } ?>
<form method="post" action="/<?= View::text($slug) ?>/missed-sale">
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
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
    <label for="price">Price the customer would have paid</label>
    <input id="price" name="price" inputmode="decimal" required value="<?= View::text($price) ?>">
    <label for="memo">What happened</label>
    <input id="memo" name="memo" required maxlength="200" value="<?= View::text($memoValue) ?>">
    <label for="posting_date">Posting date</label>
    <input id="posting_date" name="posting_date" type="date" value="<?= View::text($dateValue) ?>" required>
    <?php if ($share !== null) { ?>
        <p>Vendor share <?= View::text($share) ?>. The store expenses this amount.</p>
        <input type="hidden" name="post" value="1">
        <p><button type="submit">Post missed sale</button></p>
    <?php } else { ?>
        <p><button type="submit">Show the vendor share</button></p>
    <?php } ?>
</form>
