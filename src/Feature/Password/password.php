<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var string $email */
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Password</h1>
        <p class="muted">A recovery email is where a reset link is sent.</p>
    </div>
</div>
<div class="card mt-6">
    <div class="card-head"><div class="h3">Recovery email</div></div>
    <form class="card-body stack" method="post" action="/<?= View::text($slug) ?>/password/email">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <div class="field">
            <label for="email">Email</label>
            <input class="input" id="email" name="email" type="email" required maxlength="200" value="<?= View::text($email) ?>" autocomplete="email">
        </div>
        <div class="field">
            <label for="email_current_password">Current password</label>
            <input class="input" id="email_current_password" name="current_password" type="password" required autocomplete="current-password">
        </div>
        <p><button class="btn" type="submit">Save email</button></p>
    </form>
</div>
<div class="card mt-6">
    <div class="card-head"><div class="h3">New password</div></div>
    <form class="card-body stack" method="post" action="/<?= View::text($slug) ?>/password">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <div class="field">
            <label for="current_password">Current password</label>
            <input class="input" id="current_password" name="current_password" type="password" required autocomplete="current-password">
        </div>
        <div class="field">
            <label for="password">New password</label>
            <input class="input" id="password" name="password" type="password" required minlength="8" autocomplete="new-password">
        </div>
        <div class="field">
            <label for="confirm">Confirm new password</label>
            <input class="input" id="confirm" name="confirm" type="password" required minlength="8" autocomplete="new-password">
        </div>
        <p><button class="btn btn-primary" type="submit">Update password</button></p>
    </form>
</div>
