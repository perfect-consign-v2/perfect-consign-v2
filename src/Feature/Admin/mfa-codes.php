<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $csrf */
/** @var list<string> $codes */
/** @var \PerfectConsign\Feature\Admin\AdminPath $adminPath */
?>
<div class="login-wrap">
    <div class="panel">
        <div class="card">
            <div class="card-head"><h1>Save recovery codes</h1></div>
            <form method="post" action="<?= View::text($adminPath->url('/mfa/codes')) ?>">
                <div class="card-body grid" data-style-prop="gap:var(--space-4)">
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <p class="text-sm">These codes are shown once. Each one signs you in when the phone is gone. A password reset does not turn the authenticator off.</p>
                    <ul>
                        <?php foreach ($codes as $code): ?>
                            <li><code><?= View::text($code) ?></code></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="card-foot row" data-style-prop="justify-content:flex-end">
                    <button class="btn btn-primary" type="submit">I saved these codes</button>
                </div>
            </form>
        </div>
    </div>
</div>
