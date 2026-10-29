<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $csrf */
/** @var string|null $error */
/** @var bool $enabled */
/** @var bool $checkEmail */
$checkEmail = ($checkEmail ?? false) === true;
?>
<div class="login-wrap">
    <div class="panel">
        <div class="row gap-3" data-style-prop="justify-content:center; margin-bottom:var(--space-6)">
            <span class="brand-mark brand-mark--hero">P</span>
            <div>
                <div class="h2">Perfect Consign</div>
                <div class="text-sm muted">Create your store</div>
            </div>
        </div>
        <div class="card">
            <div class="card-head"><h1>Create your store</h1></div>
            <?php if (!$enabled): ?>
                <div class="card-body">
                    <p>Self-serve signup is turned off. Ask an operator to enable it, or use an invite.</p>
                    <p class="mt-4"><a class="btn" href="/">Back to sign in</a></p>
                </div>
            <?php elseif ($checkEmail): ?>
                <div class="card-body">
                    <p>Check your email for a confirmation link. You can sign in after you confirm.</p>
                    <p class="mt-4"><a class="btn" href="/">Back to sign in</a></p>
                </div>
            <?php else: ?>
                <form method="post" action="/signup" data-submit-once>
                    <div class="card-body grid" data-style-prop="gap:var(--space-4)">
                        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                        <?php if ($error !== null && $error !== ''): ?>
                            <p role="alert"><?= View::text($error) ?></p>
                        <?php endif; ?>
                        <div class="field">
                            <label for="store_name">Store name</label>
                            <input class="input" id="store_name" name="store_name" required maxlength="120" autocomplete="organization">
                        </div>
                        <div class="field">
                            <label for="email">Owner email</label>
                            <input class="input" id="email" name="email" type="email" required maxlength="200" autocomplete="username">
                        </div>
                        <div class="field">
                            <label for="password">Password</label>
                            <?php View::passwordField(id: 'password', name: 'password', required: true, minlength: 8, autocomplete: 'new-password'); ?>
                        </div>
                        <div class="field">
                            <label for="password_confirm">Confirm password</label>
                            <?php View::passwordField(id: 'password_confirm', name: 'password_confirm', required: true, minlength: 8, autocomplete: 'new-password'); ?>
                        </div>
                    </div>
                    <div class="card-foot row between">
                        <a href="/">Sign in</a>
                        <button class="btn btn-primary" type="submit">Create store</button>
                    </div>
                </form>
                <script>
                    (function () {
                        const form = document.querySelector('form[data-submit-once]');
                        if (!form) return;
                        form.addEventListener('submit', function () {
                            const button = form.querySelector('button[type="submit"]');
                            if (button) button.disabled = true;
                        });
                    })();
                </script>
            <?php endif; ?>
        </div>
    </div>
</div>
