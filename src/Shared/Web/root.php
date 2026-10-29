<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $csrf */
?>
<div class="login-wrap">
    <div class="panel">
        <div class="row gap-3" data-style-prop="justify-content:center; margin-bottom:var(--space-6)">
            <span class="brand-mark brand-mark--hero">P</span>
            <div>
                <div class="h2">Perfect Consign</div>
            </div>
        </div>
        <div class="card">
            <div class="card-head"><h1>Sign in</h1></div>
            <form method="post" action="/">
                <div class="card-body grid" data-style-prop="gap:var(--space-4)">
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <div class="field">
                        <label for="email">Email</label>
                        <input class="input" id="email" name="email" type="email" autocomplete="username" required autofocus value="<?= View::text(is_string($email ?? null) ? $email : '') ?>">
                    </div>
                    <div class="field">
                        <label for="password">Password</label>
                        <?php View::passwordField(id: 'password', name: 'password', required: true, autocomplete: 'current-password'); ?>
                    </div>
                </div>
                <div class="card-foot row between">
                    <span>
                        <a href="/forgot">Forgot password</a>
                        <?php if (!empty($signupEnabled)): ?>
                            · <a href="/signup">Create your store</a>
                        <?php endif; ?>
                    </span>
                    <button class="btn btn-primary" type="submit">Sign in</button>
                </div>
            </form>
        </div>
    </div>
</div>
