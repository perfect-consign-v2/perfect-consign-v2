<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $csrf */
/** @var string $token */
/** @var string $email */
?>
<div class="login-wrap">
    <div class="panel">
        <div class="card">
            <div class="card-head"><h1>Finish setting up your store</h1></div>
            <form method="post" action="/signup/verify/<?= View::text($token) ?>" data-submit-once>
                <div class="card-body grid" data-style-prop="gap:var(--space-4)">
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <p>Press the button to set up the store for <?= View::text($email) ?>.</p>
                </div>
                <div class="card-foot row between">
                    <a href="/">Sign in</a>
                    <button class="btn btn-primary" type="submit">Set up my store</button>
                </div>
            </form>
        </div>
    </div>
</div>
