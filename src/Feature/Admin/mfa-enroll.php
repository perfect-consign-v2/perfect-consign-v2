<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $csrf */
/** @var string $secret */
/** @var string $qr */
/** @var string $email */
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
            <div class="card-head"><h1>Set up authenticator</h1></div>
            <form method="post" action="<?= View::text($adminPath->url('/mfa/enroll')) ?>">
                <div class="card-body grid" data-style-prop="gap:var(--space-4)">
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <p class="text-sm">Scan this code in the authenticator app for <?= View::text($email) ?>. The account name is that email. The issuer is PC2. If the camera cannot scan, enter the key below.</p>
                    <div class="setup-qr"><?= $qr ?></div>
                    <p class="h2" data-style-prop="letter-spacing:0.04em"><?= View::text($secret) ?></p>
                    <div class="field">
                        <label for="code">Code from the app</label>
                        <input class="input" id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required>
                    </div>
                </div>
                <div class="card-foot row between">
                    <a href="<?= View::text($adminPath->url('/login')) ?>">Back</a>
                    <button class="btn btn-primary" type="submit">Confirm</button>
                </div>
            </form>
        </div>
    </div>
</div>
