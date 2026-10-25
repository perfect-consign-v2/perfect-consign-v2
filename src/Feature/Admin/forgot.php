<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $csrf */
/** @var string|null $error */
/** @var string|null $notice */
?>
<div class="login-wrap">
    <div class="panel">
        <div class="card">
            <div class="card-head"><h1>Forgot password</h1></div>
            <form method="post" action="/admin/forgot">
                <div class="card-body grid" style="gap:var(--space-4)">
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <p class="muted">Enter the platform admin email. A reset link goes to that address when it matches an admin.</p>
                    <div class="field">
                        <label for="email">Email</label>
                        <input class="input" id="email" name="email" type="email" autocomplete="username" required>
                    </div>
                </div>
                <div class="card-foot row between">
                    <a href="/admin/login">Back to sign in</a>
                    <button class="btn btn-primary" type="submit">Send reset link</button>
                </div>
            </form>
        </div>
    </div>
</div>
