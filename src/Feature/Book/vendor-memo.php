<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var list<array{id: string, name: string}> $vendors */
/** @var string|null $error */
/** @var string|null $owed */
/** @var string $vendorId */
/** @var string $direction */
/** @var string $amount */
/** @var string $reason */
/** @var string $postingDate */
/** @var string $today */
$dateValue = $postingDate !== '' ? $postingDate : $today;
$directionValue = $direction !== '' ? $direction : 'credit';
?>
<h1><?= !empty($consignmentOnly) ? 'Consignor memo' : 'Vendor memo' ?></h1>
<p class="muted"><?= !empty($consignmentOnly)
    ? 'Add or subtract what the store owes this consignor before the next check.'
    : 'Add or subtract what the mall owes this vendor before the next check. Rent is a separate bill.' ?></p>
<?php if ($error !== null && $error !== '') { ?>
    <p class="error"><?= View::text($error) ?></p>
<?php } ?>
<form method="post" action="/<?= View::text($slug) ?>/vendor-memo">
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
    <label for="vendor_id"><?= !empty($consignmentOnly) ? 'Consignor' : 'Vendor' ?></label>
    <select id="vendor_id" name="vendor_id" required>
        <option value="">Choose</option>
        <?php foreach ($vendors as $vendor) { ?>
            <option value="<?= View::text($vendor['id']) ?>"<?= $vendor['id'] === $vendorId ? ' selected' : '' ?>><?= View::text($vendor['name']) ?></option>
        <?php } ?>
    </select>
    <label for="direction">Which way</label>
    <select id="direction" name="direction" required>
        <option value="credit"<?= $directionValue === 'credit' ? ' selected' : '' ?>>We owe them</option>
        <option value="debit"<?= $directionValue === 'debit' ? ' selected' : '' ?>>They owe us</option>
    </select>
    <label for="amount">Amount</label>
    <input id="amount" name="amount" inputmode="decimal" required value="<?= View::text($amount) ?>">
    <label for="reason">Reason</label>
    <input id="reason" name="reason" required maxlength="200" value="<?= View::text($reason) ?>">
    <label for="posting_date">Posting date</label>
    <input id="posting_date" name="posting_date" type="date" value="<?= View::text($dateValue) ?>" required>
    <?php if ($owed !== null) { ?>
        <p>Open payable <?= View::text($owed) ?>.</p>
        <input type="hidden" name="post" value="1">
        <p><button type="submit">Post vendor memo</button></p>
    <?php } else { ?>
        <p><button type="submit">Show the open payable</button></p>
    <?php } ?>
</form>
