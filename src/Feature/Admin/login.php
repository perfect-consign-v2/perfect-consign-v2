<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $csrf */
/** @var string|null $error */
?>
<div class="login-wrap">
    <div class="panel">
        <div class="row gap-3" style="justify-content:center; margin-bottom:var(--space-6)">
            <span class="brand-mark" style="width:40px;height:40px;font-size:var(--text-lg)">P</span>
            <div>
                <div class="h2">Platform admin</div>
                <div class="text-sm muted">Perfect Consign</div>
            </div>
        </div>
        <div class="card">
            <div class="card-head"><h1>Sign in</h1></div>
            <form method="post" action="/admin/login">
                <div class="card-body grid" style="gap:var(--space-4)">
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <?php if (is_string($error) && $error !== ''): ?>
                        <p class="banner bad" style="margin:0"><?= View::text($error) ?></p>
                    <?php endif; ?>
                    <div class="field">
                        <label for="email">Email</label>
                        <input class="input" id="email" name="email" type="email" autocomplete="username" required>
                    </div>
                    <div class="field">
                        <label for="password">Password</label>
                        <input class="input" id="password" name="password" type="password" autocomplete="current-password" required>
                    </div>
                </div>
                <div class="card-foot row between">
                    <a href="/admin/forgot">Forgot password</a>
                    <button class="btn btn-primary" type="submit">Sign in</button>
                </div>
            </form>
        </div>
    </div>
</div>
