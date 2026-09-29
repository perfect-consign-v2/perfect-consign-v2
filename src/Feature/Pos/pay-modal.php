<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var bool $open */
/** @var int $remaining */
/** @var int $cashFill */
/** @var array<string, true> $used */
/** @var array<string, string> $ask */
/** @var array{buyer_id: string, buyer_name: string} $cart */
if (!$open || $remaining <= 0) {
    return;
}
?>
<div id="enter-payment" class="modal" role="dialog" aria-modal="true" aria-labelledby="enter-payment-title" hidden>
    <div class="modal-panel card register-pay-modal">
        <div class="card-head">
            <div class="h3" id="enter-payment-title">Payment</div>
            <button class="btn btn-sm" type="button" data-close-modal="enter-payment">Close</button>
        </div>
        <div class="card-body stack">
            <p>Still due <strong><?= View::text(View::money(max(0, $remaining))) ?></strong></p>
            <div class="register-tools">
                <?php if (!isset($used['cash']) && $cashFill > 0): ?>
                    <form method="post" action="/<?= View::text($slug) ?>/pos/tender">
                        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                        <input type="hidden" name="tender_role" value="cash">
                        <input type="hidden" name="tender_amount" value="<?= View::text(View::money($cashFill)) ?>">
                        <button class="btn" type="submit">Full Amount Cash</button>
                    </form>
                <?php endif; ?>
                <?php if (!isset($used['card'])): ?>
                    <form method="post" action="/<?= View::text($slug) ?>/pos/tender">
                        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                        <input type="hidden" name="tender_role" value="card">
                        <input type="hidden" name="tender_amount" value="<?= View::text(View::money($remaining)) ?>">
                        <button class="btn" type="submit">Full Amount Card</button>
                    </form>
                <?php endif; ?>
                <?php if (!isset($used['checks'])): ?>
                    <form method="post" action="/<?= View::text($slug) ?>/pos/tender">
                        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                        <input type="hidden" name="tender_role" value="checks">
                        <input type="hidden" name="tender_amount" value="<?= View::text(View::money($remaining)) ?>">
                        <button class="btn" type="submit">Full Amount Check</button>
                    </form>
                <?php endif; ?>
            </div>
            <?php foreach ($ask as $role => $label): ?>
                <?php if (isset($used[$role])) {
                    continue;
                } ?>
                <form class="stack" method="post" action="/<?= View::text($slug) ?>/pos/tender">
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <input type="hidden" name="tender_role" value="<?= View::text($role) ?>">
                    <?php if ($role === 'vendor_draw'): ?>
                        <input type="hidden" name="vendor_id" value="<?= View::text($cart['buyer_id']) ?>">
                    <?php endif; ?>
                    <label><?= View::text($label) ?></label>
                    <input class="input" name="tender_amount" value="<?= View::text(View::money(max(0, $remaining))) ?>" inputmode="decimal" aria-label="<?= View::text($label) ?> amount">
                    <?php if ($role === 'checks'): ?>
                        <input class="input" name="tender_reference" aria-label="Check number" placeholder="Check number">
                    <?php elseif ($role === 'gift_certificate' || $role === 'gift_card' || $role === 'store_credit'): ?>
                        <input class="input" name="tender_reference" aria-label="Number" placeholder="Number">
                    <?php elseif ($role === 'vendor_draw'): ?>
                        <p><?= $cart['buyer_name'] !== '' ? View::text($cart['buyer_name']) : 'Choose a buyer.' ?></p>
                    <?php endif; ?>
                    <button class="btn" type="submit">Apply</button>
                </form>
            <?php endforeach; ?>
        </div>
    </div>
</div>
