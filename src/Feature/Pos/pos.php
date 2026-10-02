<?php

declare(strict_types=1);

use PerfectConsign\Feature\Pos\GiftIssue;
use PerfectConsign\Ledger\CashRound;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Scalar\RowValue;

/** @var string $slug */
/** @var string $csrf */
/** @var string $templateDirectory */
/** @var string $activeRegister */
/** @var bool $chooseRegister */
/** @var array{
 *     ticket_id: string,
 *     lines: list<array{line_no: int, item_id: string, code: string, description: string, vendor: string, vendor_id: string, price: int, quantity: int, commission: int, tax: int, discount: int, line_discount: int, line_discount_kind: string, line_discount_value: string}>,
 *     merchandise: int,
 *     discount: int,
 *     discount_reason: string,
 *     tax: int,
 *     tax_bps: int,
 *     waiver: string,
 *     due: int,
 *     cash_round: int,
 *     cash_due: int,
 *     rounding: string,
 *     buyer_id: string,
 *     buyer_name: string,
 *     parked: list<array{id: string, pieces: int, sold: int, status: string}>
 * } $cart
 */
/** @var list<array{role: string, amount: int, reference: string}> $tenders */
/** @var int $deposits */
/** @var list<array{posted_on: string, tender: string, amount: int, kind: string}> $payments */
/** @var int $remaining */
/** @var string $giftNumber */
/** @var ?int $giftBalance */
/** @var ?array{
 *     journal_id: string,
 *     posting_key: string,
 *     description: string,
 *     voided: bool,
 *     lines: list<array{item_id: string, code: string, description: string, vendor: string, price: int, quantity: int, commission: int, tax: int, discount: int, returned: bool}>,
 *     tenders: list<array{name: string, amount: int}>,
 *     round: int,
 *     cash: int,
 *     discount: int,
 *     discount_reason: string,
 *     waiver: string
 * } $completed
 */
/** @var int|null $change */
/** @var bool $openInstant */
/** @var bool $openGift */
/** @var string $giftError */
/** @var string $giftFormNumber */
/** @var string $giftFormAmount */
/** @var string $giftFormTender */
/** @var string $giftFormCheck */
/** @var bool $giftCustom */
/** @var bool $openPayment */
/** @var string $payError */
/** @var string $payCash */
/** @var string $payCard */
/** @var string $payCheck */
/** @var string $payCheckNumber */
/** @var string $payVendor */
/** @var string $payGift */
/** @var string $payGiftNumber */
/** @var string $payCredit */
/** @var string $payCreditNumber */
/** @var string|null $notice */
/** @var string|null $error */
$open = $cart['ticket_id'] !== '' && $completed === null;
$locked = $completed !== null;
$lines = $cart['lines'];
$used = [];
$hasCash = false;
$cashPaid = 0;
foreach ($tenders as $tender) {
    $used[$tender['role']] = true;
    if ($tender['role'] === 'cash') {
        $hasCash = true;
        $cashPaid += RowValue::integer($tender['amount']);
    }
}
$allCash = $cart['rounding'] === '1' && $hasCash && count($used) === 1 && $deposits === 0 && $cashPaid === $cart['due'];
$roundStep = $cart['rounding'] === '1' && $deposits === 0 ? 5 : 1;
$cashFill = $roundStep === 5 ? CashRound::rounded(max(0, $remaining)) : max(0, $remaining);
$ask = [
    'checks' => 'Check',
    'gift_certificate' => 'Gift certificate',
    'gift_card' => 'Gift card',
    'store_credit' => 'Store credit',
    'vendor_draw' => 'From their sales',
];
$labels = ['cash' => 'Cash', 'card' => 'Card'] + $ask;
$parkedCount = 0;
foreach ($cart['parked'] as $held) {
    if ($held['status'] !== 'layaway') {
        $parkedCount++;
    }
}
require $templateDirectory . '/Feature/Pos/register-nav.php';
?>
<?php require $templateDirectory . '/Feature/Pos/sale-finished.php'; ?>

<div class="register">
    <section class="card">
        <div class="card-head">
            <h2 class="h3">Current sale</h2>
            <?php if (($notice !== null && $notice !== '') || ($error !== null && $error !== '')): ?>
                <div class="register-status">
                    <?php if ($notice !== null && $notice !== ''): ?>
                        <div class="alert alert-success" role="status">
                            <svg style="width:18px;height:18px;flex-shrink:0" aria-hidden="true"><use href="#i-check"></use></svg>
                            <p class="notice"><?= View::text($notice) ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if ($error !== null && $error !== ''): ?>
                        <div class="alert alert-danger" role="alert">
                            <svg style="width:18px;height:18px;flex-shrink:0" aria-hidden="true"><use href="#i-alert"></use></svg>
                            <p class="error"><?= View::text($error) ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="card-body stack">
            <fieldset class="register-lock" <?= $locked ? 'disabled' : '' ?>>
                <form class="row gap-2 register-scan" method="post" action="/<?= View::text($slug) ?>/pos/scan">
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <div class="combo register-scan-field">
                        <label for="scan">Barcode</label>
                        <input class="input" id="scan" name="scan" autocomplete="off" <?= ($locked || $openGift || $openPayment) ? '' : 'autofocus' ?> placeholder="Scan or type to search">
                        <div class="suggest" id="scan-suggest" data-url="/<?= View::text($slug) ?>/pos/suggest.json" hidden></div>
                    </div>
                    <button class="btn btn-primary" type="submit">Add</button>
                </form>
                <div class="register-tools">
                    <?php if ($open): ?>
                        <button class="btn" type="button" data-open-modal="customer">Customer<?php if ($cart['buyer_name'] !== ''): ?>: <?= View::text($cart['buyer_name']) ?><?php endif; ?></button>
                        <button class="btn" type="button" data-open-modal="cart-discount">Discount</button>
                        <button class="btn" type="button" data-open-modal="tax-free">Tax-free</button>
                    <?php endif; ?>
                    <button class="btn btn-primary" type="button" data-open-modal="instant-add">Instant Inventory</button>
                    <button class="btn" type="button" data-open-modal="gift-sell">Gift Certificate</button>
                    <?php if ($open): ?>
                        <form method="post" action="/<?= View::text($slug) ?>/pos/park">
                            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                            <button class="btn" type="submit">Suspend Sale (<?= View::text((string) $parkedCount) ?>)</button>
                        </form>
                        <button class="btn btn-danger" type="button" data-open-modal="clear-cart">Clear cart</button>
                    <?php endif; ?>
                </div>
            </fieldset>
            <?php if ($locked): ?>
                <button class="btn" type="button" data-open-modal="sale-completed">Sale completed</button>
            <?php endif; ?>
            <?php if ($lines === []): ?>
                <p class="muted"><?php if ($locked): ?>Scan and Instant inventory stay locked until you start a new sale.<?php else: ?>Cart is empty. Scan a barcode, search by code, or use <strong>Instant inventory</strong>.<?php endif; ?></p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table register-cart">
                        <thead>
                            <tr>
                                <th class="register-item">Item</th>
                                <th class="register-qty-col">Qty</th>
                                <th class="register-price-col">Price</th>
                                <th>Line discount</th>
                                <th>Line</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lines as $line): ?>
                                <tr>
                                    <td class="register-item">
                                        <p><?= View::text($line['description']) ?></p>
                                        <p class="ticket-meta"><?= View::text($line['code']) ?></p>
                                        <p class="ticket-meta"><?= View::text($line['vendor']) ?></p>
                                    </td>
                                    <td class="register-qty-col">
                                        <form method="post" action="/<?= View::text($slug) ?>/pos/quantity">
                                            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                            <input type="hidden" name="line_no" value="<?= View::text((string) $line['line_no']) ?>">
                                            <div class="register-qty">
                                                <button class="btn btn-sm" type="button" data-qty-delta="-1" <?= $line['quantity'] < 2 ? 'disabled' : '' ?> aria-label="Decrease quantity">−</button>
                                                <input class="input register-qty-input" name="quantity" value="<?= View::text((string) $line['quantity']) ?>" inputmode="numeric" aria-label="Qty">
                                                <button class="btn btn-sm" type="button" data-qty-delta="1" aria-label="Increase quantity">+</button>
                                            </div>
                                        </form>
                                    </td>
                                    <td class="register-price-col">
                                        <form method="post" action="/<?= View::text($slug) ?>/pos/price">
                                            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                            <input type="hidden" name="line_no" value="<?= View::text((string) $line['line_no']) ?>">
                                            <input class="input register-price" name="price" value="<?= View::text(View::money(RowValue::integer($line['price']))) ?>" inputmode="decimal" aria-label="Sold price">
                                        </form>
                                    </td>
                                    <td>
                                        <?php if ($line['line_discount'] > 0): ?>
                                            <p class="ticket-meta">Line discount −<?= View::text(View::money(RowValue::integer($line['line_discount']))) ?></p>
                                        <?php endif; ?>
                                        <button class="btn btn-sm" type="button" data-open-modal="line-discount-<?= View::text((string) $line['line_no']) ?>">Discount</button>
                                    </td>
                                    <td><?= View::text(View::money(RowValue::integer($line['price'] * $line['quantity'] - $line['line_discount']))) ?></td>
                                    <td>
                                        <form method="post" action="/<?= View::text($slug) ?>/pos/remove">
                                            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                            <input type="hidden" name="line_no" value="<?= View::text((string) $line['line_no']) ?>">
                                            <button class="btn btn-ghost btn-sm" type="submit">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
    <section class="card register-pay">
        <div class="card-head"><h2 class="h3">Payment summary</h2></div>
        <div class="card-body stack">
            <p>Register: <strong><?= $chooseRegister ? 'Choose a register' : View::text($activeRegister) ?></strong>
                <a class="text-link" href="/<?= View::text($slug) ?>/registers">Change</a>
            </p>
            <p class="register-summary"><span>Subtotal</span><span><?= View::text(View::money(RowValue::integer($cart['merchandise']))) ?></span></p>
            <?php if ($cart['discount'] > 0): ?>
                <p class="register-summary">
                    <span>Discount <?= View::text($cart['discount_reason']) ?></span>
                    <span id="discount"><?= View::text(View::money(RowValue::integer($cart['discount']))) ?></span>
                </p>
            <?php endif; ?>
            <?php if ($cart['waiver'] !== ''): ?>
                <p><span class="badge" id="tax-waived">Tax waived</span> <?= View::text($cart['waiver']) ?></p>
            <?php else: ?>
                <p class="register-summary"><span>Tax</span><span><?= View::text(View::money(RowValue::integer($cart['tax']))) ?></span></p>
            <?php endif; ?>
            <?php if ($allCash && !$locked): ?>
                <p class="register-summary">
                    <span>Cash round</span>
                    <span id="cash-round"><?= View::text(View::money(RowValue::integer($cart['cash_round']))) ?></span>
                </p>
            <?php endif; ?>
            <p class="register-summary"><span>Total</span><span class="register-due"><?= View::text(View::money(RowValue::integer($cart['due']))) ?></span></p>
            <p>Still due <strong><?= View::text(View::money(max(0, $remaining))) ?></strong></p>
            <p class="register-summary"><span>Total tendered</span><strong id="shell-tendered"><?= View::text(View::money(0)) ?></strong></p>
            <p id="shell-change" class="pay-note" hidden></p>
            <?php if ($deposits > 0): ?>
                <p>Layaway <?= View::text(View::money($deposits)) ?></p>
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Payment</th>
                                <th>Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td><?= View::text($payment['posted_on']) ?></td>
                                    <td><?= View::text($payment['kind']) ?> <?= View::text($payment['tender']) ?></td>
                                    <td><?= View::text(View::money($payment['amount'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <a class="btn" href="/<?= View::text($slug) ?>/pos/layaway/<?= View::text($cart['ticket_id']) ?>">Layaway receipt</a>
            <?php endif; ?>
            <?php foreach ($tenders as $tender): ?>
                <form class="tender-row" method="post" action="/<?= View::text($slug) ?>/pos/tender/remove">
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <input type="hidden" name="tender_role" value="<?= View::text($tender['role']) ?>">
                    <span><?= View::text($labels[$tender['role']] ?? $tender['role']) ?> <?= View::text(View::money(RowValue::integer($tender['amount']))) ?></span>
                    <?php if ($open): ?>
                        <button class="btn btn-ghost btn-sm" type="submit">Remove</button>
                    <?php endif; ?>
                </form>
            <?php endforeach; ?>
            <?php if ($open && $remaining > 0): ?>
                <button class="btn btn-primary btn-block" type="button" data-open-modal="enter-payment">Enter payment</button>
            <?php elseif ($locked): ?>
                <button class="btn btn-primary btn-block" type="button" disabled>Enter payment</button>
            <?php endif; ?>
            <?php if ($open): ?>
                <button class="btn btn-primary btn-block register-complete" type="submit" form="pos-pay"<?= $lines === [] || $remaining > 0 ? ' disabled' : '' ?>>Process sale</button>
            <?php elseif ($locked): ?>
                <button class="btn btn-primary btn-block register-complete" type="button" disabled>Process sale</button>
            <?php endif; ?>
        </div>
    </section>
</div>
<?php require $templateDirectory . '/Feature/Pos/pay-modal.php'; ?>
<?php if ($open): ?>
    <?php require $templateDirectory . '/Feature/Pos/adjust-modals.php'; ?>
<?php endif; ?>

<?php if ($completed !== null && $completed['voided'] !== true): ?>
<div id="void-ticket" class="modal" role="dialog" aria-modal="true" aria-labelledby="void-ticket-title" hidden>
    <form class="modal-panel card" method="post" action="/<?= View::text($slug) ?>/pos/receipt/<?= View::text($completed['journal_id']) ?>/void">
        <div class="card-head"><div class="h3" id="void-ticket-title">Void ticket</div></div>
        <div class="card-body stack">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <p>Voiding reverses this sale and frees every piece.</p>
            <button class="btn btn-primary" type="submit">Confirm void</button>
            <button class="btn" type="button" data-close-modal="void-ticket">Cancel</button>
        </div>
    </form>
</div>
<?php endif; ?>

<?php if ($open): ?>
<div id="customer" class="modal" role="dialog" aria-modal="true" aria-labelledby="customer-title" hidden>
    <div class="modal-panel card register-customer">
        <div class="card-head">
            <div class="h3" id="customer-title">Customer</div>
            <button class="btn" type="button" data-close-modal="customer">Close</button>
        </div>
        <div class="card-body stack">
            <form class="stack" method="post" action="/<?= View::text($slug) ?>/pos/buyer" data-vendors="/<?= View::text($slug) ?>/inventory/vendors.json" id="buyer-form">
                <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                <div class="field combo">
                    <label for="buyer">Find buyer</label>
                    <input class="input" id="buyer" value="<?= View::text($cart['buyer_name']) ?>" autocomplete="off" placeholder="Name or vendor number">
                    <input type="hidden" id="buyer_id" name="vendor_id" value="<?= View::text($cart['buyer_id']) ?>">
                    <div class="suggest" id="buyer-suggest" hidden></div>
                </div>
                <p>Selected: <?= $cart['buyer_name'] !== '' ? View::text($cart['buyer_name']) : 'None' ?></p>
            </form>
            <?php if ($cart['buyer_id'] !== ''): ?>
                <form method="post" action="/<?= View::text($slug) ?>/pos/buyer">
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <button class="btn" type="submit">Clear customer</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<div id="clear-cart" class="modal" role="dialog" aria-modal="true" aria-labelledby="clear-cart-title" hidden>
    <form class="modal-panel card" method="post" action="/<?= View::text($slug) ?>/pos/discard">
        <div class="card-head"><div class="h3" id="clear-cart-title">Clear cart</div></div>
        <div class="card-body stack">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <p>This removes the open ticket.</p>
            <button class="btn btn-primary" type="submit">Clear cart</button>
            <button class="btn" type="button" data-close-modal="clear-cart">Cancel</button>
        </div>
    </form>
</div>
<?php endif; ?>
<div id="instant-add" class="modal" role="dialog" aria-modal="true" aria-labelledby="instant-add-title"<?= $openInstant ? '' : ' hidden' ?>>
    <form id="instant-form" class="modal-panel card register-instant" method="post" action="/<?= View::text($slug) ?>/pos/instant" data-vendors="/<?= View::text($slug) ?>/inventory/vendors.json" data-brands="/<?= View::text($slug) ?>/inventory/brands.json" data-brand-create="/<?= View::text($slug) ?>/inventory/brands">
        <div class="card-head">
            <div class="h3" id="instant-add-title">Instant inventory</div>
            <button class="btn" type="button" data-close-modal="instant-add">Close</button>
        </div>
        <div class="card-body stack">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <div class="field">
                <label for="instant_vendor">Vendor #</label>
                <input class="input" id="instant_vendor" name="vendor_code" autocomplete="off" required placeholder="Vendor #"<?= $openInstant ? ' autofocus' : '' ?>>
                <input type="hidden" id="instant_vendor_id" name="vendor_id">
                <p class="muted text-sm" id="instant-vendor-hint" hidden></p>
                <p class="field-error" id="instant-vendor-error" hidden>No vendor found with that number.</p>
            </div>
            <div class="field">
                <label for="instant_description">Item description *</label>
                <input class="input" id="instant_description" name="description" required placeholder="Short description for tags and receipt">
            </div>
            <div class="grid cols-2">
                <div class="field">
                    <label for="instant_price">Price *</label>
                    <input class="input" id="instant_price" name="price" inputmode="decimal" required>
                </div>
                <div class="field">
                    <label for="instant_quantity">Quantity *</label>
                    <input class="input" id="instant_quantity" name="quantity" inputmode="numeric" required value="1">
                </div>
            </div>
            <div class="field">
                <label for="instant_discount_kind">Line discount</label>
                <div class="register-line">
                    <select class="input" id="instant_discount_kind" name="discount_kind" aria-label="Line discount type">
                        <option value="consignor_default" selected>Consignor default</option>
                        <option value="none">None</option>
                        <option value="percent">%</option>
                        <option value="flat">$</option>
                    </select>
                    <input class="input" id="instant_discount_value" name="discount_value" inputmode="decimal" placeholder="0" aria-label="Line discount value" hidden disabled>
                </div>
                <p class="muted text-sm">Consignor default applies the vendor’s default POS line discount when set. Choose None for no discount, or enter a percent or flat amount.</p>
            </div>
            <div class="field combo">
                <label for="instant_brand">Brand</label>
                <input class="input" id="instant_brand" name="brand_label" autocomplete="off" placeholder="Search brands or type a new one">
                <input type="hidden" id="instant_brand_id" name="brand_id">
                <div class="suggest" id="instant-brand-suggest" hidden></div>
                <div class="row gap-2" id="instant-brand-create" hidden>
                    <span class="muted text-sm">Add brand <strong id="instant-brand-create-name"></strong>?</span>
                    <button class="btn btn-sm" id="instant-brand-create-button" type="button">Add brand</button>
                </div>
                <p class="field-error" id="instant-brand-error" hidden></p>
            </div>
            <div class="field">
                <label for="instant_barcode">Barcode</label>
                <input class="input" id="instant_barcode" name="barcode" maxlength="100">
                <p class="muted text-sm">Leave blank and a barcode is generated.</p>
            </div>
            <div class="row gap-2">
                <button class="btn btn-primary" type="submit" name="instant_action" value="save_and_new">Save and New</button>
                <button class="btn" type="submit" name="instant_action" value="save_and_close">Save &amp; Close</button>
            </div>
        </div>
    </form>
</div>
<?php
$giftCheckError = $giftError === GiftIssue::CHECK_REQUIRED;
$giftNumberError = $giftError === GiftIssue::NUMBER_REQUIRED;
$giftNumberOpen = $giftCustom || $giftFormNumber !== '';
$giftFormError = ($giftError !== '' && !$giftCheckError && !$giftNumberError) ? $giftError : '';
?>
<div id="gift-sell" class="modal" role="dialog" aria-modal="true" aria-labelledby="gift-sell-title"<?= $openGift ? '' : ' hidden' ?>>
    <form class="modal-panel card" method="post" action="/<?= View::text($slug) ?>/pos/gift">
        <div class="card-head">
            <div class="h3" id="gift-sell-title">Sell gift certificate</div>
            <button class="btn" type="button" data-close-modal="gift-sell">Close</button>
        </div>
        <div class="card-body stack">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <p class="field-error" id="gift-sell-error"<?= $giftFormError === '' ? ' hidden' : '' ?>><?= View::text($giftFormError) ?></p>
            <div class="field">
                <label for="gift_amount">Amount</label>
                <input class="input" id="gift_amount" name="gift_amount" inputmode="decimal" required placeholder="0.00" value="<?= View::text($giftFormAmount) ?>"<?= ($openGift && !$giftCheckError && !$giftNumberOpen) ? ' autofocus' : '' ?>>
            </div>
            <div class="field">
                <span class="strong">Paid with</span>
                <div class="segmented" role="radiogroup" aria-label="Paid with">
                    <?php foreach (['cash' => 'Cash', 'card' => 'Card', 'checks' => 'Check'] as $tender => $tenderLabel): ?>
                        <label class="seg-btn"><input class="sr-only" type="radio" name="gift_tender" value="<?= View::text($tender) ?>"<?= $giftFormTender === $tender ? ' checked' : '' ?>> <?= View::text($tenderLabel) ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="field" id="gift-check-field"<?= $giftFormTender === 'checks' ? '' : ' hidden' ?>>
                <label for="gift_check">Check number</label>
                <input class="input" id="gift_check" name="check_number" value="<?= View::text($giftFormCheck) ?>"<?= $giftCheckError ? ' aria-invalid="true" aria-describedby="gift-check-error" autofocus' : '' ?>>
                <p class="field-error" id="gift-check-error"<?= $giftCheckError ? '' : ' hidden' ?>><?= View::text(GiftIssue::CHECK_REQUIRED) ?></p>
            </div>
            <div class="field">
                <label for="gift_custom">
                    <input id="gift_custom" name="gift_custom" type="checkbox" value="1"<?= $giftNumberOpen ? ' checked' : '' ?>>
                    This certificate already has a number
                </label>
            </div>
            <div class="field" id="gift-number-field"<?= $giftNumberOpen ? '' : ' hidden' ?>>
                <label for="gift_number">Certificate number</label>
                <input class="input" id="gift_number" name="gift_number" value="<?= View::text($giftFormNumber) ?>" maxlength="40"<?= $giftNumberError ? ' aria-invalid="true" aria-describedby="gift-number-error"' : '' ?><?= ($openGift && !$giftCheckError && $giftNumberOpen) ? ' autofocus' : '' ?>>
                <p class="field-error" id="gift-number-error"<?= $giftNumberError ? '' : ' hidden' ?>><?= View::text(GiftIssue::NUMBER_REQUIRED) ?></p>
            </div>
            <div class="row">
                <button class="btn btn-primary" type="submit">Sell</button>
            </div>
        </div>
    </form>
</div>
<?php require $templateDirectory . '/Feature/Pos/register-ui.php'; ?>
