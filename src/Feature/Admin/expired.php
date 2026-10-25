<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string|null $error */
?>
<div class="login-wrap">
    <div class="panel">
        <div class="card">
            <div class="card-head"><h1>Form expired</h1></div>
            <div class="card-body">
                <p><?= View::text(is_string($error) ? $error : 'The form expired. Go back and try again.') ?></p>
            </div>
            <div class="card-foot">
                <a class="btn" href="/admin/login">Back to sign in</a>
            </div>
        </div>
    </div>
</div>
