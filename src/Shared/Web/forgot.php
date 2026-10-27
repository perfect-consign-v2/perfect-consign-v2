<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $csrf */
?>
<div class="login-wrap">
    <div class="panel">
        <div class="row gap-3" style="justify-content:center; margin-bottom:var(--space-6)">
            <span class="brand-mark" style="width:40px;height:40px;font-size:var(--text-lg)">P</span>
            <div>
                <div class="h2">Perfect Consign</div>
                <div class="text-sm muted">Mall register</div>
            </div>
        </div>
        <div class="card">
            <div class="card-head"><h1>Forgot password</h1></div>
            <form method="post" action="/forgot">
                <div class="card-body grid" style="gap:var(--space-4)">
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <p class="muted">Enter the recovery email for your sign-in. If that email is on file, a reset link is on its way.</p>
                    <div class="field">
                        <label for="email">Email</label>
                        <input class="input" id="email" name="email" type="email" autocomplete="username" required autofocus>
                    </div>
                </div>
                <div class="card-foot row between">
                    <a href="/">Back to sign in</a>
                    <button class="btn btn-primary" type="submit">Send reset link</button>
                </div>
            </form>
        </div>
    </div>
</div>
