<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $csrf */
/** @var \PerfectConsign\Feature\Admin\AdminPath $adminPath */
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
            <div class="card-head"><h1>Authenticator code</h1></div>
            <form method="post" action="<?= View::text($adminPath->url('/mfa')) ?>">
                <div class="card-body grid" data-style-prop="gap:var(--space-4)">
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <p class="text-sm">Enter the 6-digit code from your authenticator app, or one recovery code.</p>
                    <div class="field">
                        <label for="code">Authenticator code</label>
                        <input class="input" id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6">
                    </div>
                    <div class="field">
                        <label for="recovery_code">Recovery code</label>
                        <input class="input" id="recovery_code" name="recovery_code" type="text" autocomplete="off" maxlength="40">
                    </div>
                </div>
                <div class="card-foot row between">
                    <a href="<?= View::text($adminPath->url('/login')) ?>">Back</a>
                    <button class="btn btn-primary" type="submit">Continue</button>
                </div>
            </form>
        </div>
    </div>
</div>
