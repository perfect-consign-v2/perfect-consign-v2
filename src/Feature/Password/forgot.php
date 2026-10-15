<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var string $displayName */
?>
<div class="login-wrap">
    <div class="panel">
        <div class="row gap-3" style="justify-content:center; margin-bottom:var(--space-6)">
            <span class="brand-mark" style="width:40px;height:40px;font-size:var(--text-lg)"><?= View::text($displayName !== '' ? strtoupper(substr($displayName, 0, 1)) : 'P') ?></span>
            <div>
                <div class="h2"><?= View::text($displayName) ?></div>
                <div class="text-sm muted">Perfect Consign</div>
            </div>
        </div>
        <div class="card">
            <div class="card-head"><h1>Forgot password</h1></div>
            <form method="post" action="/<?= View::text($slug) ?>/forgot">
                <div class="card-body grid" style="gap:var(--space-4)">
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <p class="muted">Enter the sign-in name. If it has a recovery email, a link is sent there.</p>
                    <div class="field">
                        <label for="username">Username</label>
                        <input class="input" id="username" name="username" autocomplete="username" required>
                    </div>
                </div>
                <div class="card-foot row between">
                    <a href="/<?= View::text($slug) ?>/login">Back to sign in</a>
                    <button class="btn btn-primary" type="submit">Send reset link</button>
                </div>
            </form>
        </div>
    </div>
</div>
