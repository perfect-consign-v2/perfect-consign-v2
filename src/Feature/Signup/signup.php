<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $csrf */
/** @var string|null $error */
/** @var bool $enabled */
/** @var array{seed: string, bits: int} $proof */
/** @var string $storeName */
/** @var string $email */
/** @var string $password */
/** @var string $passwordConfirm */
$storeName = is_string($storeName ?? null) ? $storeName : '';
$email = is_string($email ?? null) ? $email : '';
$password = is_string($password ?? null) ? $password : '';
$passwordConfirm = is_string($passwordConfirm ?? null) ? $passwordConfirm : '';
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
            <?php else: ?>
                <form method="post" action="/signup" data-submit-once
                      data-proof-seed="<?= View::text($proof['seed']) ?>"
                      data-proof-bits="<?= View::text((string) $proof['bits']) ?>">
                    <div class="card-body grid" data-style-prop="gap:var(--space-4)">
                        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                        <input type="hidden" name="proof_seed" value="<?= View::text($proof['seed']) ?>">
                        <input type="hidden" name="proof_nonce" value="">
                        <?php if ($error !== null && $error !== ''): ?>
                            <p class="field-error" role="alert"><?= View::text($error) ?></p>
                        <?php endif; ?>
                        <noscript>
                            <p class="field-error" role="alert">Turn on JavaScript in your browser to create a store.</p>
                        </noscript>
                        <div class="field">
                            <label for="store_name">Store name</label>
                            <input class="input" id="store_name" name="store_name" required maxlength="120" autocomplete="organization" value="<?= View::text($storeName) ?>">
                        </div>
                        <div class="field">
                            <label for="email">Owner email</label>
                            <input class="input" id="email" name="email" type="email" required maxlength="200" autocomplete="username" value="<?= View::text($email) ?>">
                        </div>
                        <div class="field">
                            <label for="password">Password</label>
                            <?php View::requiredPasswordField(id: 'password', name: 'password', minlength: 8, autocomplete: 'new-password', value: $password); ?>
                        </div>
                        <div class="field">
                            <label for="password_confirm">Confirm password</label>
                            <?php View::requiredPasswordField(id: 'password_confirm', name: 'password_confirm', minlength: 8, autocomplete: 'new-password', value: $passwordConfirm); ?>
                        </div>
                        <p class="muted text-sm" data-proof-status aria-live="polite">Your browser is checking that you are a person. This takes a moment.</p>
                    </div>
                    <div class="card-foot row between">
                        <a href="/">Sign in</a>
                        <button class="btn btn-primary" type="submit" disabled data-proof-button>Create store</button>
                    </div>
                </form>
                <script src="<?= View::asset('/assets/js/signup-proof.js') ?>" defer></script>
            <?php endif; ?>
        </div>
    </div>
</div>
