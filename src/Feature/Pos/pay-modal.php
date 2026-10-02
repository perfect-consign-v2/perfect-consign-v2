<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var bool $open */
/** @var int $remaining */
/** @var int $cashFill */
/** @var int $roundStep */
/** @var int $salesAvailable */
/** @var array{lines: list<mixed>, buyer_id: string, buyer_name: string} $cart */
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
if (!$open) {
    return;
}
$due = max(0, $remaining);
$cashDue = max(0, $cashFill);
$sales = max(0, $salesAvailable);
$canDraw = $cart['buyer_id'] !== '' && $sales > 0;
$amount = static function (int $minor): string {
    return intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
};
?>
<div id="enter-payment" class="modal" role="dialog" aria-modal="true" aria-labelledby="enter-payment-title"<?= $openPayment ? '' : ' hidden' ?>>
    <div class="modal-panel card register-pay-modal">
        <div class="card-head">
            <div class="h3" id="enter-payment-title">Payment</div>
            <button class="btn btn-sm" type="button" data-close-modal="enter-payment">Close</button>
        </div>
        <form id="pos-pay" class="pay-split" method="post" action="/<?= View::text($slug) ?>/pos/pay" data-due="<?= View::text($amount($due)) ?>" data-cash="<?= View::text($amount($cashDue)) ?>" data-round="<?= View::text((string) $roundStep) ?>" data-lines="<?= View::text((string) count($cart['lines'])) ?>" data-sales="<?= View::text((string) $sales) ?>" data-buyer="<?= View::text($cart['buyer_id']) ?>" data-gift-lookup="/<?= View::text($slug) ?>/pos/gift-certificate.json">
            <div class="card-body stack">
                <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                <input type="hidden" name="vendor_id" value="<?= View::text($cart['buyer_id']) ?>">
                <div class="pay-quick" role="group" aria-label="Pay the full amount">
                    <button class="btn" type="button" data-pay-full="cash">Full Amount Cash</button>
                    <button class="btn" type="button" data-pay-full="card">Full Amount Card</button>
                    <button class="btn" type="button" data-pay-full="checks">Full Amount Check</button>
                </div>
                <div class="pay-grid">
                    <div class="field">
                        <label for="pay-cash">Cash</label>
                        <input class="input" id="pay-cash" name="tender_cash" inputmode="decimal" placeholder="0.00" value="<?= View::text($payCash) ?>">
                    </div>
                    <div class="field">
                        <label for="pay-card">Card</label>
                        <input class="input" id="pay-card" name="tender_card" inputmode="decimal" placeholder="0.00" value="<?= View::text($payCard) ?>">
                    </div>
                    <div class="field">
                        <label for="pay-check">Check</label>
                        <input class="input" id="pay-check" name="tender_checks" inputmode="decimal" placeholder="0.00" value="<?= View::text($payCheck) ?>">
                    </div>
                    <div class="field">
                        <label for="pay-check-number">Check number</label>
                        <input class="input" id="pay-check-number" name="check_number" autocomplete="off" aria-describedby="pay-check-error" value="<?= View::text($payCheckNumber) ?>">
                        <p class="field-error" id="pay-check-error" hidden>Enter a check number when taking payment by check.</p>
                    </div>
                </div>
                <div class="field">
                    <label for="pay-vendor">Pay from sales</label>
                    <div class="pay-sales">
                        <input class="input" id="pay-vendor" name="tender_vendor_draw" inputmode="decimal" placeholder="0.00" value="<?= View::text($payVendor) ?>"<?= $canDraw ? '' : ' disabled' ?>>
                        <button class="btn" id="pay-sales-max" type="button"<?= $canDraw ? '' : ' disabled' ?>>Apply max from sales</button>
                    </div>
                    <p class="muted text-sm" id="pay-sales-hint"><?php if ($cart['buyer_id'] === ''): ?>Choose a buyer.<?php elseif ($sales > 0): ?>Available from sales: <?= View::text(View::money($sales)) ?> (cannot exceed this on checkout).<?php else: ?>No Pay from Sales balance is available for this buyer on this sale.<?php endif; ?></p>
                </div>
                <div class="pay-grid">
                    <div class="field">
                        <label for="pay-gift">Gift certificate</label>
                        <input class="input" id="pay-gift" name="tender_gift_certificate" inputmode="decimal" placeholder="0.00" value="<?= View::text($payGift) ?>">
                    </div>
                    <div class="field">
                        <label for="pay-gift-number">Gift certificate number</label>
                        <div class="pay-sales">
                            <input class="input" id="pay-gift-number" name="gift_certificate_number" autocomplete="off" value="<?= View::text($payGiftNumber) ?>" aria-describedby="pay-gift-error pay-gift-balance"<?= $payError !== '' ? ' aria-invalid="true" autofocus' : '' ?>>
                            <button class="btn" id="pay-gift-lookup" type="button">Look up</button>
                        </div>
                        <p class="field-error" id="pay-gift-error"<?= $payError === '' ? ' hidden' : '' ?>><?= View::text($payError) ?></p>
                        <p class="muted text-sm" id="pay-gift-balance" hidden></p>
                    </div>
                    <div class="field">
                        <label for="pay-credit">Store credit</label>
                        <input class="input" id="pay-credit" name="tender_store_credit" inputmode="decimal" placeholder="0.00" value="<?= View::text($payCredit) ?>">
                    </div>
                    <div class="field">
                        <label for="pay-credit-number">Store credit number</label>
                        <input class="input" id="pay-credit-number" name="store_credit_number" autocomplete="off" value="<?= View::text($payCreditNumber) ?>">
                    </div>
                </div>
            </div>
            <div class="pay-rail">
                <div class="pay-math">
                    <p class="register-summary"><span>Subtotal</span><strong><?= View::text(View::money($due)) ?></strong></p>
                    <p class="register-summary" id="pay-round" hidden><span>Cash rounding</span><strong id="pay-round-amount"></strong></p>
                    <p class="register-summary" id="pay-settled" hidden><span>Amount due</span><strong id="pay-settled-amount"></strong></p>
                </div>
                <div class="pay-collected">
                    <p class="register-summary"><span>Total tendered</span><strong id="pay-tendered"><?= View::text(View::money(0)) ?></strong></p>
                    <p id="pay-change" class="pay-note" hidden></p>
                </div>
                <button class="btn" id="pay-hold" type="submit" name="hold" value="1" disabled>Hold layaway</button>
                <button class="btn" id="pay-done" type="button" data-close-modal="enter-payment">Done</button>
            </div>
        </form>
    </div>
</div>
