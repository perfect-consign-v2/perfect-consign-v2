<?php

declare(strict_types=1);

use PerfectConsign\Feature\Catalog\NameList;
use PerfectConsign\Ledger\CashRound;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Scalar\RowValue;

/** @var string $slug */
/** @var list<NameList> $attributeLists */
/** @var bool $showBrand */
$showBrand = ($showBrand ?? true) === true;
/** @var string $csrf */
/** @var string $templateDirectory */
/** @var string $activeRegister */
/** @var bool $chooseRegister */
/** @var array{
 *     ticket_id: string,
 *     posting_key: string,
 *     lines: list<array{line_no: int, item_id: string, code: string, description: string, vendor: string, vendor_id: string, price: int, quantity: int, commission: int, commission_bps: int, tax: int, discount: int, line_discount: int, line_discount_kind: string, line_discount_value: string}>,
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
 *     parked: list<array{id: string, items: int, sold: int, status: string, label: string}>
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
$nonCashTender = false;
foreach ($tenders as $tender) {
    if ($tender['role'] !== 'cash') {
        $nonCashTender = true;
        break;
    }
}
$owed = max(0, $remaining);
$roundStep = $cart['rounding'] === '1' && $deposits === 0 ? 5 : 1;
$cashFill = $roundStep === 5 ? CashRound::rounded($owed) : $owed;
$cashAdjustment = $roundStep === 5 ? CashRound::adjustment($owed) : 0;
$showCashDue = $open && !$nonCashTender && $cashAdjustment !== 0;
$cashRoundText = $cashAdjustment > 0 ? '+' . View::money($cashAdjustment) : View::money($cashAdjustment);
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
        </div>
        <div class="card-body stack">
            <p id="sale-posting" class="muted"><?php if ($open): ?>This sale is not posted.<?php endif; ?></p>
            <p id="ticket-refusal" class="form-error" hidden></p>
            <fieldset class="register-lock" <?= $locked ? 'disabled' : '' ?>>
                <form class="row gap-2 register-scan ticket-command" method="post" action="/<?= View::text($slug) ?>/pos/scan">
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <div class="combo register-scan-field">
                        <label for="scan">Scan or type</label>
                        <input class="input" id="scan" name="scan" autocomplete="off" <?= ($locked || $openGift || $openPayment) ? '' : 'autofocus' ?> placeholder="Scan or type">
                        <div class="suggest" id="scan-suggest" data-url="/<?= View::text($slug) ?>/pos/suggest.json" hidden></div>
                    </div>
                    <button class="btn btn-primary" type="submit">Add</button>
                </form>
                <div class="register-tools">
                    <?php if ($open): ?>
                        <button class="btn" type="button" data-open-modal="customer">Customer<?php if (is_string($customerName ?? null) && $customerName !== ''): ?>: <?= View::text($customerName) ?><?php endif; ?></button>
                        <button class="btn" type="button" data-open-modal="cart-discount">Discount</button>
                        <button class="btn" type="button" data-open-modal="tax-free">Tax-free</button>
                    <?php endif; ?>
                    <button class="btn btn-primary" type="button" data-open-modal="instant-add">Instant Inventory</button>
                    <button class="btn" type="button" data-open-modal="gift-sell">Gift Certificate</button>
                    <?php if ($open): ?>
                        <form class="row gap-2 ticket-command" method="post" action="/<?= View::text($slug) ?>/pos/park">
                            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                            <input class="input" name="park_label" maxlength="40" placeholder="red hat" aria-label="Name for this suspended sale">
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
                                        <?php
                                        $offerName = is_string($line['offer_name'] ?? null) ? $line['offer_name'] : '';
                                        $offerOff = RowValue::integer($line['offer_discount'] ?? 0) + RowValue::integer($line['store_discount'] ?? 0);
                                        ?>
                                        <?php if ($offerName !== '' && $offerOff > 0): ?>
                                            <p class="ticket-meta"><?= View::text($offerName) ?> −<?= View::text(View::money($offerOff)) ?></p>
                                        <?php endif; ?>
                                        <?php if ($line['line_discount'] > 0): ?>
                                            <p class="ticket-meta">Line discount −<?= View::text(View::money(RowValue::integer($line['line_discount']))) ?></p>
                                        <?php endif; ?>
                                        <button class="btn btn-sm" type="button" data-open-modal="line-discount-<?= View::text((string) $line['line_no']) ?>">Discount</button>
                                    </td>
                                    <td><?= View::text(View::money(RowValue::integer($line['price'] * $line['quantity'] - $line['line_discount'] - $offerOff))) ?></td>
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
            <p class="register-summary"><span>Total</span><span class="register-due"><?= View::text(View::money(RowValue::integer($cart['due']))) ?></span></p>
            <p>Still due <strong><?= View::text(View::money($owed)) ?></strong></p>
            <?php if ($showCashDue): ?>
                <p class="register-summary" id="summary-cash-round">
                    <span>Cash round</span>
                    <span id="summary-cash-round-amount"><?= View::text($cashRoundText) ?></span>
                </p>
                <p class="register-summary" id="summary-cash-due">
                    <span>Cash due</span>
                    <span class="register-cash-due" id="summary-cash-due-amount"><?= View::text(View::money($cashFill)) ?></span>
                </p>
            <?php endif; ?>
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
                                    <td><?= View::date($payment['posted_on']) ?></td>
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
                <button class="btn btn-primary btn-block register-complete" type="submit" form="pos-pay"<?= $lines === [] || $remaining > 0 ? ' disabled' : '' ?>>Finish sale</button>
            <?php elseif ($locked): ?>
                <button class="btn btn-primary btn-block register-complete" type="button" disabled>Finish sale</button>
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
            <p>Voiding reverses this sale and frees every item. A cashier needs a second person.</p>
            <label for="void_override_user">Second person</label>
            <input id="void_override_user" name="override_user" autocomplete="off">
            <label for="void_override_password">Their password</label>
            <input id="void_override_password" name="override_password" type="password" autocomplete="off">
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
            <form class="stack" method="post" action="/<?= View::text($slug) ?>/pos/customer">
                <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                <div class="field">
                    <label for="customer_name">Name</label>
                    <input class="input" id="customer_name" name="customer_name" value="<?= View::text(is_string($customerName ?? null) ? $customerName : '') ?>" required>
                </div>
                <div class="field">
                    <label for="customer_phone">Phone</label>
                    <input class="input" id="customer_phone" name="customer_phone">
                </div>
                <div class="field">
                    <label for="customer_email">Email</label>
                    <input class="input" id="customer_email" name="customer_email">
                </div>
                <button class="btn btn-primary" type="submit">Save customer</button>
            </form>
            <?php if (is_string($customerName ?? null) && $customerName !== ''): ?>
                <form method="post" action="/<?= View::text($slug) ?>/pos/customer">
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
<?php if ($open): ?>
<div id="sale-vendor" class="modal" role="dialog" aria-modal="true" aria-labelledby="sale-vendor-title" hidden>
    <div class="modal-panel card">
        <div class="card-head">
            <div class="h3" id="sale-vendor-title">Pay from sales</div>
            <button class="btn" type="button" data-close-modal="sale-vendor">Close</button>
        </div>
        <div class="card-body stack">
            <form class="stack" method="post" action="/<?= View::text($slug) ?>/pos/buyer" data-vendors="/<?= View::text($slug) ?>/inventory/vendors.json" id="buyer-form">
                <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                <div class="field combo">
                    <label for="buyer"><?= !empty($consignmentOnly) ? 'Consignor' : 'Vendor' ?></label>
                    <input class="input" id="buyer" value="<?= View::text($cart['buyer_name']) ?>" autocomplete="off" placeholder="<?= !empty($consignmentOnly) ? 'Name or consignor number' : 'Name or vendor number' ?>">
                    <input type="hidden" id="buyer_id" name="vendor_id" value="<?= View::text($cart['buyer_id']) ?>">
                    <div class="suggest" id="buyer-suggest" hidden></div>
                </div>
                <p>Selected: <?= $cart['buyer_name'] !== '' ? View::text($cart['buyer_name']) : 'None' ?></p>
            </form>
            <?php if ($cart['buyer_id'] !== ''): ?>
                <form method="post" action="/<?= View::text($slug) ?>/pos/buyer">
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <button class="btn" type="submit">Clear <?= !empty($consignmentOnly) ? 'consignor' : 'vendor' ?></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>
<?php
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
            <p class="error" id="instant-error" role="alert"<?= $instantError === '' ? ' hidden' : '' ?>><?= View::text($instantError) ?></p>
            <?php if ($instantAdded): ?>
                <p class="alert alert-success" role="status">Item added to cart.</p>
            <?php endif; ?>
            <div class="field combo">
                <label for="instant_vendor">Consignor</label>
                <input class="input" id="instant_vendor" name="vendor_label" value="<?= View::text($instantVendorLabel) ?>" autocomplete="off" required placeholder="<?= !empty($consignmentOnly) ? 'Name or consignor number' : 'Name or vendor number' ?>"<?= ($openInstant && !$instantPriceError) ? ' autofocus' : '' ?>>
                <input type="hidden" id="instant_vendor_id" name="vendor_id" value="<?= View::text($instantVendorId) ?>">
                <div class="suggest" id="instant-vendor-suggest" hidden></div>
                <p class="muted text-sm" id="instant-vendor-hint" hidden></p>
                <p class="field-error" id="instant-vendor-error" hidden></p>
            </div>
            <div class="field">
                <label for="instant_description">Description</label>
                <input class="input" id="instant_description" name="description" value="<?= View::text($instantDescription) ?>" required placeholder="Short description for tags and receipt">
            </div>
            <div class="field">
                <label for="instant_price">Price</label>
                <input class="input" id="instant_price" name="price" value="<?= View::text($instantPrice) ?>" inputmode="decimal" required<?= $instantPriceError ? ' aria-invalid="true" aria-describedby="instant-error" autofocus' : '' ?>>
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
<?php require $templateDirectory . '/Feature/Pos/gift-sell.php'; ?>
<?php require $templateDirectory . '/Feature/Pos/register-ui.php'; ?>
<script src="/assets/js/ticket-commands.js"></script>
<script src="/assets/js/register-outbox.js"></script>
