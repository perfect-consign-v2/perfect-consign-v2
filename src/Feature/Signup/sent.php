<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $sentence */
?>
<div class="login-wrap">
    <div class="panel">
        <div class="card">
            <div class="card-head"><h1>Check your email</h1></div>
            <div class="card-body">
                <p><?= View::text($sentence) ?></p>
                <p class="muted text-sm mt-4">No email after a few minutes? Look in the spam folder, or start again.</p>
                <p class="mt-4"><a class="btn" href="/">Back to sign in</a></p>
            </div>
        </div>
    </div>
</div>
