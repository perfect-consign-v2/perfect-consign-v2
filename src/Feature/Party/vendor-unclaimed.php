<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $csrf */
/** @var string $today */
/** @var string $sellerBase */
/** @var bool $isOwner */
/** @var array{id: string, name: string} $vendor */
/** @var array{owed: int, since: string, movable_on: string, held: int} $unpaid */

$record = $sellerBase . '/' . $vendor['id'];
$first = explode(' ', $vendor['name'])[0];
?>
<?php if ($isOwner && ($unpaid['held'] > 0 || ($unpaid['owed'] > 0 && $unpaid['movable_on'] !== '' && $unpaid['movable_on'] <= $today))): ?>
<section class="card mt-6" aria-labelledby="vendor-unclaimed">
    <div class="card-head"><div class="h3" id="vendor-unclaimed">Money the store cannot pay</div></div>
    <div class="card-body stack">
        <?php if ($unpaid['owed'] > 0 && $unpaid['movable_on'] !== '' && $unpaid['movable_on'] <= $today): ?>
            <p>The store owes <?= View::text($first) ?> <?= View::text(View::money($unpaid['owed'])) ?>, and nothing has moved on it since <?= View::date($unpaid['since']) ?>. If you cannot reach <?= View::text($first) ?>, move it to unclaimed money. It is not the store's money: pay <?= View::text($first) ?> from it if they come back, or send it to the state.</p>
            <form class="stack" method="post" action="<?= View::text($record) ?>/unclaimed">
                <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                <div class="field">
                    <label for="unclaimed-reason">Why the store cannot pay</label>
                    <input class="input" id="unclaimed-reason" name="reason" maxlength="500" required>
                </div>
                <p><button class="btn" type="submit">Move <?= View::text(View::money($unpaid['owed'])) ?> to unclaimed money</button></p>
            </form>
        <?php endif; ?>
        <?php if ($unpaid['held'] > 0): ?>
            <p>The store holds <?= View::text(View::money($unpaid['held'])) ?> of unclaimed money for <?= View::text($first) ?>.</p>
            <details class="text-sm">
                <summary><?= View::text($first) ?> came back</summary>
                <form class="stack" method="post" action="<?= View::text($record) ?>/unclaimed/pay">
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <div class="field">
                        <label for="unclaimed-pay-amount">Amount</label>
                        <input class="input" id="unclaimed-pay-amount" name="amount" inputmode="decimal" value="<?= View::text(number_format($unpaid['held'] / 100, 2, '.', '')) ?>" required>
                    </div>
                    <fieldset class="field choice-row">
                        <legend>Pay by</legend>
                        <label><input type="radio" name="pay_with" value="cash"> Cash from the register</label>
                        <label><input type="radio" name="pay_with" value="check"> Check</label>
                    </fieldset>
                    <div class="field">
                        <label for="unclaimed-check">Check number (for a check)</label>
                        <input class="input" id="unclaimed-check" name="check_number" maxlength="40">
                    </div>
                    <p><button class="btn btn-sm" type="submit">Pay <?= View::text($first) ?></button></p>
                </form>
            </details>
            <details class="text-sm">
                <summary>Sent to the state</summary>
                <form class="stack" method="post" action="<?= View::text($record) ?>/unclaimed/remit">
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <div class="field">
                        <label for="unclaimed-remit-amount">Amount</label>
                        <input class="input" id="unclaimed-remit-amount" name="amount" inputmode="decimal" value="<?= View::text(number_format($unpaid['held'] / 100, 2, '.', '')) ?>" required>
                    </div>
                    <div class="field">
                        <label for="unclaimed-reference">The state's report number</label>
                        <input class="input" id="unclaimed-reference" name="state_reference" maxlength="80" required>
                    </div>
                    <p><button class="btn btn-sm" type="submit">Record it as sent</button></p>
                </form>
            </details>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>
