<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var list<array<string, mixed>> $vendors */
/** @var list<array{id: string, name: string}> $registers */
/** @var string $saleId */
/** @var string $today */
?>
<h1>Sale</h1>
<?php if ($registers === []): ?>
    <p class="error">Open a register before posting a sale.</p>
<?php endif; ?>
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
    <?php if (($activeSessionId ?? '') !== ''): ?>
        <input type="hidden" name="register_session_id" value="<?= View::text($activeSessionId) ?>">
        <p>Active register: <?= View::text($activeRegister ?? '') ?>.</p>
    <?php elseif (($chooseRegister ?? false) === true): ?>
        <p class="error">Choose the active register.</p>
        <p><a href="/<?= View::text($slug) ?>/registers">Choose a register</a></p>
    <?php endif; ?>
    <label for="tender_cash">Cash</label>
    <input id="tender_cash" name="tender_cash" inputmode="decimal" value="0.00">
    <label for="tender_card">Card</label>
    <input id="tender_card" name="tender_card" inputmode="decimal" value="0.00">
    <label for="tender_checks">Checks</label>
    <input id="tender_checks" name="tender_checks" inputmode="decimal" value="0.00">
    <label for="check_number">Check number</label>
    <input id="check_number" name="check_number">
    <label for="tender_gift_certificate">Gift certificate</label>
    <input id="tender_gift_certificate" name="tender_gift_certificate" inputmode="decimal" value="0.00">
    <label for="gift_certificate_number">Gift certificate number</label>
    <input id="gift_certificate_number" name="gift_certificate_number">
    <label for="tender_gift_card">Gift card</label>
    <input id="tender_gift_card" name="tender_gift_card" inputmode="decimal" value="0.00">
    <label for="gift_card_number">Gift card number</label>
    <input id="gift_card_number" name="gift_card_number">
    <label for="tender_store_credit">Store credit</label>
    <input id="tender_store_credit" name="tender_store_credit" inputmode="decimal" value="0.00">
    <label for="store_credit_number">Store credit number</label>
    <input id="store_credit_number" name="store_credit_number">
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
