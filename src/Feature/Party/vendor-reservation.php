<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var bool $isOwner */
/** @var array{id: string, starts_on: string} $assignment */
/** @var int $held */

?>
<div class="muted text-sm">Reserved: paid <?= View::text(View::money($held)) ?> for <?= View::date($assignment['starts_on']) ?></div>
<?php if ($isOwner): ?>
    <details class="text-sm">
        <summary>Cancel reservation</summary>
        <form class="stack" method="post" action="/<?= View::text($slug) ?>/assignments/<?= View::text($assignment['id']) ?>/cancel">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <div class="field">
                <label for="cancel-reason-<?= View::text($assignment['id']) ?>">Why</label>
                <input class="input" id="cancel-reason-<?= View::text($assignment['id']) ?>" name="reason" maxlength="200" required>
            </div>
            <fieldset class="field choice-row">
                <legend>Give it back by</legend>
                <label><input type="radio" name="pay_with" value="cash"> Cash</label>
                <label><input type="radio" name="pay_with" value="card"> Card</label>
                <label><input type="radio" name="pay_with" value="check"> Check</label>
            </fieldset>
            <div class="field">
                <label for="cancel-check-<?= View::text($assignment['id']) ?>">Check number (for a check)</label>
                <input class="input" id="cancel-check-<?= View::text($assignment['id']) ?>" name="check_number" maxlength="40">
            </div>
            <p class="row gap-2">
                <button class="btn" type="submit" name="outcome" value="refund">Refund <?= View::text(View::money($held)) ?></button>
                <button class="btn" type="submit" name="outcome" value="keep">Keep <?= View::text(View::money($held)) ?></button>
            </p>
        </form>
    </details>
<?php endif; ?>
