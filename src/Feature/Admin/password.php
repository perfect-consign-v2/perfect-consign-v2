<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $csrf */
?>
<h1>Change password</h1>
<div class="card" data-style-prop="max-width:28rem">
    <form method="post" action="/admin/password">
        <div class="card-body grid" data-style-prop="gap:var(--space-4)">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <p class="muted">Changing your password signs you out. You will need to sign in again with the new password.</p>
            <div class="field">
                <label for="current">Current password</label>
                <?php View::requiredPasswordField(id: 'current', name: 'current', autocomplete: 'current-password'); ?>
            </div>
            <div class="field">
                <label for="password">New password</label>
                <?php View::requiredPasswordField(id: 'password', name: 'password', minlength: 8, autocomplete: 'new-password'); ?>
            </div>
            <div class="field">
                <label for="confirm">Confirm password</label>
                <?php View::requiredPasswordField(id: 'confirm', name: 'confirm', minlength: 8, autocomplete: 'new-password'); ?>
            </div>
        </div>
        <div class="card-foot">
            <button class="btn btn-primary" type="submit">Save password</button>
        </div>
    </form>
</div>
