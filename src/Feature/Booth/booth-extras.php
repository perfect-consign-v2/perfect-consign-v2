<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var ?string $error */
/** @var list<array{id: string, label: string}> $stays */
/** @var list<array{id: string, label: string}> $held */
/** @var bool $depositsOn */

$stays = $stays ?? [];
$held = $held ?? [];
$depositsOn = ($depositsOn ?? false) === true;
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Booth extras</h1>
        <p class="muted">Charges besides rent<?= $depositsOn ? ', and booth deposits' : '' ?></p>
    </div>
</div>
<?php if ($error !== null && $error !== ''): ?>
    <p class="alert alert-danger" role="alert"><?= View::text($error) ?></p>
<?php endif; ?>

<?php if ($stays === []): ?>
    <div class="card mt-6"><div class="empty"><p>No booth is rented right now.</p></div></div>
<?php else: ?>
<form class="card mt-6" method="post" action="/<?= View::text($slug) ?>/booth-extras">
    <div class="card-head"><div class="h3">Charge an extra</div></div>
    <div class="card-body stack">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <p class="muted">Electric, a sign, advertising. It is added to what they owe for the booth.</p>
        <div class="field">
            <label for="charge_stay">Booth</label>
            <select class="input" id="charge_stay" name="assignment_id" required>
                <option value="">Choose a booth</option>
                <?php foreach ($stays as $choice): ?>
                    <option value="<?= View::text($choice['id']) ?>"><?= View::text($choice['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="source_reference">What it is for</label>
            <input class="input" id="source_reference" name="source_reference" maxlength="100" required>
        </div>
        <div class="field">
            <label for="amount">Amount</label>
            <input class="input" id="amount" name="amount" inputmode="decimal" required>
        </div>
        <p><button class="btn btn-primary" type="submit">Charge</button></p>
    </div>
</form>
<?php endif; ?>

<?php if ($depositsOn && $stays !== []): ?>
<form class="card mt-6" method="post" action="/<?= View::text($slug) ?>/booth-deposit">
    <div class="card-head"><div class="h3">Take a deposit</div></div>
    <div class="card-body stack">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <p class="muted">Cash into the drawer. The store holds it for them.</p>
        <div class="field">
            <label for="deposit_stay">Booth</label>
            <select class="input" id="deposit_stay" name="assignment_id" required>
                <option value="">Choose a booth</option>
                <?php foreach ($stays as $choice): ?>
                    <option value="<?= View::text($choice['id']) ?>"><?= View::text($choice['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="deposit_reference">Note</label>
            <input class="input" id="deposit_reference" name="source_reference" maxlength="100" required>
        </div>
        <div class="field">
            <label for="deposit_amount">Amount</label>
            <input class="input" id="deposit_amount" name="amount" inputmode="decimal" required>
        </div>
        <p><button class="btn btn-primary" type="submit">Take deposit</button></p>
    </div>
</form>
<?php endif; ?>

<?php if ($depositsOn && $held !== []): ?>
<form class="card mt-6" method="post" action="/<?= View::text($slug) ?>/booth-deposit/release">
    <div class="card-head"><div class="h3">Give a deposit back</div></div>
    <div class="card-body stack">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <p class="muted">Cash out of the drawer, back to them.</p>
        <div class="field">
            <label for="release_stay">Booth</label>
            <select class="input" id="release_stay" name="assignment_id" required>
                <option value="">Choose a booth</option>
                <?php foreach ($held as $choice): ?>
                    <option value="<?= View::text($choice['id']) ?>"><?= View::text($choice['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="release_reference">Note</label>
            <input class="input" id="release_reference" name="source_reference" maxlength="100" required>
        </div>
        <div class="field">
            <label for="release_amount">Amount</label>
            <input class="input" id="release_amount" name="amount" inputmode="decimal" required>
        </div>
        <p><button class="btn" type="submit">Give it back</button></p>
    </div>
</form>
<?php endif; ?>
