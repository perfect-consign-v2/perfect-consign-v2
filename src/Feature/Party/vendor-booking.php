<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var string $today */
/** @var string $earliestStart */
/** @var array{id: string} $vendor */
/** @var list<array{id: string, code: string, rent: string, available_on: string}> $booths */
/** @var array{booth: string, vendor: string, starts_on: string, ends_on: string, rent: ?int, due: int, first_period: string, period_end: string, paid_ahead: bool, rent_typed: string}|null $booking */

$boothCode = '';
if ($booking !== null) {
    foreach ($booths as $booth) {
        if ($booth['id'] === $booking['booth']) {
            $boothCode = $booth['code'];
        }
    }
}
?>
<div class="card">
    <div class="card-head"><div class="h3"><?= $booking === null ? 'Assign a booth' : 'Take the first rent' ?></div></div>
    <?php if ($booking === null): ?>
        <form method="post" action="/<?= View::text($slug) ?>/assignments" data-today="<?= View::text($today) ?>">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <input type="hidden" name="vendor_id" value="<?= View::text($vendor['id']) ?>">
            <div class="card-body stack">
                <div class="field">
                    <label for="assign_booth">Booth</label>
                    <select class="select" id="assign_booth" name="booth_id" required>
                        <option value="">Choose a booth</option>
                        <?php foreach ($booths as $booth): ?>
                            <option value="<?= View::text($booth['id']) ?>" data-rent="<?= View::text($booth['rent']) ?>" data-available="<?= View::text($booth['available_on']) ?>"><?= View::text($booth['code']) ?><?php if ($booth['available_on'] !== ''): ?> — available <?= View::date($booth['available_on']) ?><?php endif; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="assign_rent">Rent <span class="badge badge-warning" id="assign_rent_badge" hidden></span></label>
                    <input class="input" id="assign_rent" name="rent" inputmode="decimal">
                    <p class="muted text-sm">Default booth rent. You can change it.</p>
                </div>
                <div class="field">
                    <label for="starts_on">Start</label>
                    <input class="input" id="starts_on" name="starts_on" type="date" value="<?= View::text($today) ?>" min="<?= View::text($earliestStart) ?>" required>
                    <p class="muted text-sm">The first rent is paid when the booth is assigned.</p>
                </div>
                <div class="field">
                    <label for="ends_on">End</label>
                    <input class="input" id="ends_on" name="ends_on" type="date">
                </div>
            </div>
            <div class="card-foot">
                <button class="btn btn-primary" type="submit">See what is due</button>
            </div>
        </form>
        <script src="/assets/js/vendor-assign.js"></script>
    <?php else: ?>
        <form method="post" action="/<?= View::text($slug) ?>/assignments" id="assign-pay">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <input type="hidden" name="vendor_id" value="<?= View::text($vendor['id']) ?>">
            <input type="hidden" name="booth_id" value="<?= View::text($booking['booth']) ?>">
            <input type="hidden" name="starts_on" value="<?= View::text($booking['starts_on']) ?>">
            <input type="hidden" name="ends_on" value="<?= View::text($booking['ends_on']) ?>">
            <input type="hidden" name="rent" value="<?= View::text($booking['rent_typed']) ?>">
            <div class="card-body stack">
                <p>Booth <?= View::text($boothCode) ?></p>
                <p class="h3" id="assign-due">
                    Due now: <?= View::text(View::money($booking['due'])) ?>
                    <?php if ($booking['starts_on'] === $booking['first_period']): ?>
                        (full month, starts <?= View::date($booking['starts_on']) ?>)
                    <?php else: ?>
                        (<?= View::date($booking['starts_on']) ?> to <?= View::date($booking['period_end']) ?>)
                    <?php endif; ?>
                </p>
                <?php if ($booking['paid_ahead']): ?>
                    <p class="muted">The store holds it for them until the booth starts.</p>
                <?php endif; ?>
                <fieldset class="field choice-row">
                    <legend>How they pay</legend>
                    <label><input type="radio" name="pay_with" value="cash" required> Cash</label>
                    <label><input type="radio" name="pay_with" value="card"> Card</label>
                    <label><input type="radio" name="pay_with" value="check"> Check</label>
                </fieldset>
                <div class="field">
                    <label for="assign_check">Check number (for a check)</label>
                    <input class="input" id="assign_check" name="check_number" maxlength="40">
                </div>
            </div>
            <div class="card-foot row gap-2">
                <button class="btn btn-primary" type="submit">Take <?= View::text(View::money($booking['due'])) ?> and assign</button>
                <a class="btn" href="/<?= View::text($slug) ?>/vendors/<?= View::text($vendor['id']) ?>">Start over</a>
            </div>
        </form>
    <?php endif; ?>
</div>
