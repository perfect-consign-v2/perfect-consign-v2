<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var string $token */
/** @var bool $open */
?>
<div class="login-wrap">
    <div class="panel">
        <div class="card">
            <div class="card-head"><h1>Reset password</h1></div>
            <?php if ($open): ?>
                <form method="post" action="/<?= View::text($slug) ?>/reset/<?= View::text($token) ?>">
                    <div class="card-body grid" data-style-prop="gap:var(--space-4)">
                        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                        <div class="field">
                            <label for="password">New password</label>
                            <?php View::requiredPasswordField(id: 'password', name: 'password', minlength: 8, autocomplete: 'new-password'); ?>
                        </div>
                        <div class="field">
                            <label for="confirm">Confirm new password</label>
                            <?php View::requiredPasswordField(id: 'confirm', name: 'confirm', minlength: 8, autocomplete: 'new-password'); ?>
                        </div>
                    </div>
                    <div class="card-foot row between">
                        <a href="/">Back to sign in</a>
                        <button class="btn btn-primary" type="submit">Save password</button>
                    </div>
                </form>
            <?php else: ?>
                <div class="card-body">
                    <p><a href="/forgot">Request a new link</a></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
