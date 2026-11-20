<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $csrf */
/** @var string $token */
/** @var string $email */
/** @var string|null $error */
?>
<div class="login-wrap">
    <div class="panel">
        <div class="card">
            <div class="card-head"><h1>Confirm your store</h1></div>
            <form method="post" action="/signup/verify/<?= View::text($token) ?>" data-submit-once>
                <div class="card-body grid" data-style-prop="gap:var(--space-4)">
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <?php if ($error !== null && $error !== ''): ?>
                        <p role="alert"><?= View::text($error) ?></p>
                    <?php endif; ?>
                    <p>Confirm <?= View::text($email) ?> and open your store. You chose the password when you created the store. Opening this page does not sign you in.</p>
                </div>
                <div class="card-foot row between">
                    <a href="/">Sign in</a>
                    <button class="btn btn-primary" type="submit">Confirm and open your store</button>
                </div>
            </form>
        </div>
    </div>
</div>
