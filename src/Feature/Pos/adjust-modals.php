<?php

declare(strict_types=1);

use PerfectConsign\Feature\Pos\TicketAdjustment;
use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var array{
 *     lines: list<array{line_no: int, description: string, line_discount_kind: string, line_discount_value: string}>,
 *     discount_reason: string,
 *     waiver: string
 * } $cart
 */

$lines = $cart['lines'];
?>
<?php foreach ($lines as $line): ?>
    <?php $modalId = 'line-discount-' . (string) $line['line_no']; ?>
    <div id="<?= View::text($modalId) ?>" class="modal" role="dialog" aria-modal="true" aria-labelledby="<?= View::text($modalId) ?>-title" hidden>
        <form class="modal-panel card" method="post" action="/<?= View::text($slug) ?>/pos/line-discount">
            <div class="card-head">
                <div class="h3" id="<?= View::text($modalId) ?>-title">Line discount</div>
                <button class="btn" type="button" data-close-modal="<?= View::text($modalId) ?>">Close</button>
            </div>
            <div class="card-body stack">
                <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                <input type="hidden" name="line_no" value="<?= View::text((string) $line['line_no']) ?>">
                <p><?= View::text($line['description']) ?></p>
                <div class="field">
                    <span class="strong">Type</span>
                    <div class="segmented" role="radiogroup" aria-label="Type">
                        <label class="seg-btn"><input class="sr-only" type="radio" name="discount_kind" value="percent"<?= $line['line_discount_kind'] !== 'amount' ? ' checked' : '' ?>> Percent</label>
                        <label class="seg-btn"><input class="sr-only" type="radio" name="discount_kind" value="amount"<?= $line['line_discount_kind'] === 'amount' ? ' checked' : '' ?>> Amount</label>
                    </div>
                </div>
                <div class="field">
                    <label for="<?= View::text($modalId) ?>-value">Value</label>
                    <input class="input" id="<?= View::text($modalId) ?>-value" name="discount_value" inputmode="decimal" placeholder="0" value="<?= View::text($line['line_discount_value']) ?>">
                </div>
                <div class="field">
                    <label for="<?= View::text($modalId) ?>-reason">Reason</label>
                    <select class="select" id="<?= View::text($modalId) ?>-reason" name="discount_reason" required>
                        <option value="">Choose a reason</option>
                        <?php foreach (TicketAdjustment::DISCOUNT_REASONS as $reason): ?>
                            <option value="<?= View::text($reason) ?>"><?= View::text($reason) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row between">
                    <button class="btn" type="submit" name="discount_action" value="remove" formnovalidate>Remove discount</button>
                    <button class="btn btn-primary" type="submit" name="discount_action" value="apply">Apply</button>
                </div>
            </div>
        </form>
    </div>
<?php endforeach; ?>
<div id="cart-discount" class="modal" role="dialog" aria-modal="true" aria-labelledby="cart-discount-title" hidden>
    <form class="modal-panel card" method="post" action="/<?= View::text($slug) ?>/pos/discount">
        <div class="card-head">
            <div class="h3" id="cart-discount-title">Apply discount</div>
            <button class="btn" type="button" data-close-modal="cart-discount">Close</button>
        </div>
        <div class="card-body stack">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <div class="field">
                <span class="strong">Type</span>
                <div class="segmented" role="radiogroup" aria-label="Type">
                    <label class="seg-btn"><input class="sr-only" type="radio" name="discount_kind" value="percent" checked> Percent</label>
                    <label class="seg-btn"><input class="sr-only" type="radio" name="discount_kind" value="amount"> Amount</label>
                </div>
            </div>
            <div class="field">
                <label for="discount_value">Value</label>
                <input class="input" id="discount_value" name="discount_value" inputmode="decimal" placeholder="e.g. 10">
            </div>
            <div class="field">
                <label for="discount_reason">Reason</label>
                <select class="select" id="discount_reason" name="discount_reason" required>
                    <option value="">Choose a reason</option>
                    <?php foreach (TicketAdjustment::DISCOUNT_REASONS as $reason): ?>
                        <option value="<?= View::text($reason) ?>"<?= $cart['discount_reason'] === $reason ? ' selected' : '' ?>><?= View::text($reason) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="override_user">Second person</label>
                <input class="input" id="override_user" name="override_user" autocomplete="off">
            </div>
            <div class="field">
                <label for="override_password">Their password</label>
                <input class="input" id="override_password" name="override_password" type="password" autocomplete="off">
            </div>
            <div class="row between">
                <button class="btn" type="submit" name="discount_action" value="remove" formnovalidate>Remove discount</button>
                <button class="btn btn-primary" type="submit" name="discount_action" value="apply">Apply</button>
            </div>
        </div>
    </form>
</div>
<div id="tax-free" class="modal" role="dialog" aria-modal="true" aria-labelledby="tax-free-title" hidden>
    <form class="modal-panel card" method="post" action="/<?= View::text($slug) ?>/pos/tax-free">
        <div class="card-head">
            <div class="h3" id="tax-free-title">Tax-free</div>
            <button class="btn" type="button" data-close-modal="tax-free">Close</button>
        </div>
        <div class="card-body stack">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <p>Tax will be waived on this sale. A reason is required.</p>
            <div class="field">
                <label for="waiver_reason">Reason</label>
                <select class="select" id="waiver_reason" name="waiver_reason" required>
                    <option value="">Choose a reason</option>
                    <?php foreach (TicketAdjustment::WAIVER_REASONS as $reason): ?>
                        <option value="<?= View::text($reason) ?>"<?= $cart['waiver'] === $reason ? ' selected' : '' ?>><?= View::text($reason) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="tax_override_user">Second person</label>
                <input class="input" id="tax_override_user" name="override_user" autocomplete="off">
            </div>
            <div class="field">
                <label for="tax_override_password">Their password</label>
                <input class="input" id="tax_override_password" name="override_password" type="password" autocomplete="off">
            </div>
            <div class="row between">
                <button class="btn" type="submit" name="waiver_action" value="restore" formnovalidate>Restore tax</button>
                <button class="btn btn-primary" type="submit" name="waiver_action" value="waive">Waive tax</button>
            </div>
        </div>
    </form>
</div>
