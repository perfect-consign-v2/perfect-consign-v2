<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $csrf */
/** @var string $token */
/** @var string $email */
/** @var string|null $error */
?>
<div class="login-wrap">
    <div class="panel">
        <div class="card">
            <div class="card-head"><h1>Choose your password</h1></div>
            <form method="post" action="/signup/verify/<?= View::text($token) ?>" data-submit-once>
                <div class="card-body grid" data-style-prop="gap:var(--space-4)">
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <?php if ($error !== null && $error !== ''): ?>
                        <p role="alert"><?= View::text($error) ?></p>
                    <?php endif; ?>
                    <p>Choose a password for <?= View::text($email) ?>. This page does not sign you in until you submit it.</p>
                    <div class="field">
                        <label for="password">Password</label>
                        <?php View::requiredPasswordField(id: 'password', name: 'password', minlength: 8, autocomplete: 'new-password'); ?>
                    </div>
                    <div class="field">
                        <label for="password_confirm">Confirm password</label>
                        <?php View::requiredPasswordField(id: 'password_confirm', name: 'password_confirm', minlength: 8, autocomplete: 'new-password'); ?>
                    </div>
                </div>
                <div class="card-foot row between">
                    <a href="/">Sign in</a>
                    <button class="btn btn-primary" type="submit">Save password</button>
                </div>
            </form>
        </div>
    </div>
</div>
