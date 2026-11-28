<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var array{wait_days: string, deposit_covers_rent: bool, deposits_on: bool} $rules */
/** @var string|null $error */

?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Settings</h1>
        <p class="muted">When rent or returns a vendor never paid can be written off as a loss.</p>
    </div>
</div>
<?php $settingsPart = 'bad-debts'; require __DIR__ . '/settings-tabs.php'; ?>

<?php if ($error !== null): ?>
    <div class="alert alert-danger mt-4" role="alert"><?= View::text($error) ?></div>
<?php endif; ?>

<form class="card mt-6" method="post" action="/<?= View::text($slug) ?>/settings/bad-debts">
    <div class="card-head"><div class="h3">Writing off what a vendor owes</div></div>
    <div class="card-body stack">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <p class="muted text-sm">Only the owner writes a debt off, with a reason, after paying out or applying anything the store owes that vendor. If they pay later, the owner can still take the money.</p>
        <div class="field">
            <label for="write_off_wait_days">Days past due before a debt can be written off</label>
            <input class="input" id="write_off_wait_days" name="write_off_wait_days" inputmode="numeric" maxlength="4" value="<?= View::text($rules['wait_days']) ?>" required>
        </div>
        <?php if ($rules['deposits_on']): ?>
            <label class="switch-row" for="deposit_covers_rent">
                <span class="switch">
                    <input id="deposit_covers_rent" name="deposit_covers_rent" type="checkbox" value="1"<?= $rules['deposit_covers_rent'] ? ' checked' : '' ?>>
                    <span class="switch-track" aria-hidden="true"></span>
                    <span class="switch-knob" aria-hidden="true"></span>
                </span>
                Apply a held booth deposit to unpaid rent before a write-off
            </label>
        <?php elseif ($rules['deposit_covers_rent']): ?>
            <input type="hidden" name="deposit_covers_rent" value="1">
        <?php endif; ?>
    </div>
    <div class="card-foot"><button class="btn btn-primary" type="submit">Save</button></div>
</form>
