<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var list<array<string, mixed>> $vendors */
/** @var string $saleId */
/** @var string $today */
?>
<h1>Sale</h1>
<form method="post" action="/<?= View::text($slug) ?>/sale">
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
    <label for="sale_id">Sale id</label>
    <input id="sale_id" name="sale_id" value="<?= View::text($saleId) ?>" required>
    <label for="vendor_id">Vendor</label>
    <select id="vendor_id" name="vendor_id" required>
        <?php foreach ($vendors as $vendor): ?>
            <option value="<?= View::text($vendor['id']) ?>"><?= View::text($vendor['code']) ?></option>
        <?php endforeach; ?>
    </select>
    <label for="tender">Tender</label>
    <select id="tender" name="tender">
        <option value="cash">Cash</option>
        <option value="card">Card</option>
    </select>
    <label for="amount">Amount</label>
    <input id="amount" name="amount" inputmode="decimal" required>
    <label for="commission">Commission</label>
    <input id="commission" name="commission" inputmode="decimal" value="0.00">
    <label for="tax">Sales tax</label>
    <input id="tax" name="tax" inputmode="decimal" value="0.00">
    <label for="posting_date">Posting date</label>
    <input id="posting_date" name="posting_date" type="date" value="<?= View::text($today) ?>" required>
    <p><button type="submit">Post sale</button></p>
</form>
