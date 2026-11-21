<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $csrf */
/** @var \PerfectConsign\Feature\Admin\AdminPath $adminPath */
/** @var bool $enabled */
/** @var bool $enrolled */
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Authenticator</h1>
        <p class="muted">
            <?= $enabled
                ? 'Authenticator is on. Sign-in asks for a code.'
                : 'Authenticator is off. Sign-in is email and password.' ?>
        </p>
    </div>
</div>
<form class="card mt-6" method="post" action="<?= View::text($adminPath->url('/authenticator')) ?>" data-style-prop="max-width:28rem">
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
    <div class="card-body stack">
        <label class="row gap-2">
            <input type="checkbox" name="admin_mfa_enabled" value="1"<?= $enabled ? ' checked' : '' ?>>
            Require an authenticator code at sign-in
        </label>
        <p class="muted text-sm">This applies to every platform admin. Turning it off leaves the phone setup in place. Turning it back on asks for a code from that phone.</p>
        <div class="field">
            <label for="current">Current password</label>
            <?php View::requiredPasswordField(id: 'current', name: 'current', autocomplete: 'current-password'); ?>
        </div>
        <?php if ($enabled && $enrolled): ?>
            <div class="field">
                <label for="code">Authenticator code</label>
                <input class="input" id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6">
                <p class="muted text-sm">Required to turn the authenticator off. The code you just used to sign in will not work. Wait for the next code.</p>
            </div>
        <?php endif; ?>
        <p><button class="btn btn-primary" type="submit">Save</button></p>
    </div>
</form>
