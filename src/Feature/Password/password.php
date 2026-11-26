<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var string $email */
/** @var list<array{id: string, label: string, created_at: string, last_seen_at: string, current: bool}> $devices */
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
                <?php View::requiredPasswordField(id: 'current_password', name: 'current_password', autocomplete: 'current-password'); ?>
            </div>
            <div class="field">
                <label for="password">New password</label>
                <?php View::requiredPasswordField(id: 'password', name: 'password', minlength: 8, autocomplete: 'new-password'); ?>
            </div>
            <div class="field">
                <label for="confirm">Confirm new password</label>
                <?php View::requiredPasswordField(id: 'confirm', name: 'confirm', minlength: 8, autocomplete: 'new-password'); ?>
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
                <?php View::requiredPasswordField(id: 'email_current_password', name: 'current_password', autocomplete: 'current-password'); ?>
            </div>
            <p><button class="btn" type="submit">Save email</button></p>
        </form>
    </div>
</div>
<div class="card mt-6">
    <div class="card-head"><div class="h3">Browsers you signed in from</div></div>
    <div class="card-body stack">
        <p class="muted">If someone keeps guessing your password, sign-in is stopped for a while, but not on these browsers. Remove one you do not use any more. It will need the password the next time.</p>
        <?php if ($devices === []): ?>
            <p class="muted">No browsers yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>Browser</th>
                        <th>First signed in</th>
                        <th>Last signed in</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($devices as $device): ?>
                        <tr>
                            <td>
                                <?= View::text($device['label']) ?>
                                <?php if ($device['current']): ?>
                                    <br><span class="muted text-sm">This browser</span>
                                <?php endif; ?>
                            </td>
                            <td><?= View::text($device['created_at']) ?></td>
                            <td><?= View::text($device['last_seen_at']) ?></td>
                            <td>
                                <form method="post" action="/<?= View::text($slug) ?>/password/devices/remove">
                                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                    <input type="hidden" name="device_id" value="<?= View::text($device['id']) ?>">
                                    <button class="btn" type="submit">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
