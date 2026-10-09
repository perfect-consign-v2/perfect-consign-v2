<?php

declare(strict_types=1);

use PerfectConsign\Feature\Pos\GiftIssue;
use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var bool $openGift */
/** @var string $giftError */
/** @var string $giftFormNumber */
/** @var string $giftFormAmount */
/** @var string $giftFormTender */
/** @var string $giftFormCheck */
/** @var bool $giftCustom */
/** @var string $giftFormKind */

$giftFormKind = is_string($giftFormKind ?? null) ? $giftFormKind : 'gift_certificate';
$giftCheckError = $giftError === GiftIssue::CHECK_REQUIRED;
$giftNumberError = $giftError === GiftIssue::NUMBER_REQUIRED;
$giftNumberOpen = $giftCustom || $giftFormNumber !== '';
$giftFormError = ($giftError !== '' && !$giftCheckError && !$giftNumberError) ? $giftError : '';
?>
<div id="gift-sell" class="modal" role="dialog" aria-modal="true" aria-labelledby="gift-sell-title"<?= $openGift ? '' : ' hidden' ?>>
    <form class="modal-panel card" method="post" action="/<?= View::text($slug) ?>/pos/gift">
        <div class="card-head">
            <div class="h3" id="gift-sell-title">Sell a number</div>
            <button class="btn" type="button" data-close-modal="gift-sell">Close</button>
        </div>
        <div class="card-body stack">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <div class="field">
                <label for="gift_kind">Kind</label>
                <select class="select" id="gift_kind" name="gift_kind">
                    <option value="gift_certificate"<?= $giftFormKind === 'gift_certificate' ? ' selected' : '' ?>>Gift certificate</option>
                    <option value="gift_card"<?= $giftFormKind === 'gift_card' ? ' selected' : '' ?>>Gift card</option>
                    <option value="store_credit"<?= $giftFormKind === 'store_credit' ? ' selected' : '' ?>>Store credit</option>
                </select>
                <p class="muted text-sm">Leave the number blank and the mall assigns one. A person can type one instead.</p>
            </div>
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
