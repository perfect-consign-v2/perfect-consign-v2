<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $csrf */
/** @var string $token */
/** @var bool $expired */
$expired = ($expired ?? false) === true;
?>
<div class="login-wrap">
    <div class="panel">
        <div class="row gap-3" data-style-prop="justify-content:center; margin-bottom:var(--space-6)">
            <span class="brand-mark brand-mark--hero">P</span>
            <div>
                <div class="h2">Platform</div>
                <div class="text-sm muted">Perfect Consign</div>
            </div>
        </div>
        <div class="card">
            <div class="card-head"><h1>Reset password</h1></div>
            <?php if ($expired): ?>
                <div class="card-body">
                    <p class="muted">That reset link expired. Request a new one.</p>
                </div>
                <div class="card-foot row between">
                    <a href="/admin/forgot">Request a new link</a>
                    <a class="btn" href="/admin/login">Sign in</a>
                </div>
            <?php else: ?>
                <form method="post" action="/admin/reset/<?= View::text($token) ?>">
                    <div class="card-body grid" data-style-prop="gap:var(--space-4)">
                        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                        <div class="field">
                            <label for="password">New password</label>
                            <?php View::passwordField(id: 'password', name: 'password', required: true, minlength: 8, autocomplete: 'new-password'); ?>
                        </div>
                        <div class="field">
                            <label for="confirm">Confirm password</label>
                            <?php View::passwordField(id: 'confirm', name: 'confirm', required: true, minlength: 8, autocomplete: 'new-password'); ?>
                        </div>
                    </div>
                    <div class="card-foot row between">
                        <a href="/admin/login">Back to sign in</a>
                        <button class="btn btn-primary" type="submit">Save password</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
