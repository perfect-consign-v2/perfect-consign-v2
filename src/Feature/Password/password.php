<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var string $email */
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Change password</h1>
    </div>
</div>
<div class="grid cols-2 mt-6">
    <div class="card">
        <div class="card-head"><div class="h3">Password</div></div>
        <form class="card-body stack" method="post" action="/<?= View::text($slug) ?>/password">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <p class="muted">Changing your password signs you out. You will need to sign in again with the new password.</p>
            <div class="field">
                <label for="current_password">Current password</label>
                <?php View::passwordField(id: 'current_password', name: 'current_password', required: true, autocomplete: 'current-password'); ?>
            </div>
            <div class="field">
                <label for="password">New password</label>
                <?php View::passwordField(id: 'password', name: 'password', required: true, minlength: 8, autocomplete: 'new-password'); ?>
            </div>
            <div class="field">
                <label for="confirm">Confirm new password</label>
                <?php View::passwordField(id: 'confirm', name: 'confirm', required: true, minlength: 8, autocomplete: 'new-password'); ?>
            </div>
            <p><button class="btn btn-primary" type="submit">Update password</button></p>
        </form>
    </div>
    <div class="card">
        <div class="card-head"><div class="h3">Sign-in email</div></div>
        <form class="card-body stack" method="post" action="/<?= View::text($slug) ?>/password/email">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <p class="muted">Used for email sign-in and password-reset links. Each email can belong to only one account. Saving a new email signs you out. Sign in again with that email.</p>
            <div class="field">
                <label for="email">Email</label>
                <input class="input" id="email" name="email" type="email" required maxlength="200" value="<?= View::text($email) ?>" autocomplete="email">
            </div>
            <div class="field">
                <label for="email_current_password">Current password</label>
                <?php View::passwordField(id: 'email_current_password', name: 'current_password', required: true, autocomplete: 'current-password'); ?>
            </div>
            <p><button class="btn" type="submit">Save email</button></p>
        </form>
    </div>
</div>
