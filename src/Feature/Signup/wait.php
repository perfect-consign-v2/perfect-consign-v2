<?php

declare(strict_types=1);

/** @var bool $slow */
?>
<div class="login-wrap">
    <div class="panel">
        <div class="card">
            <div class="card-head"><h1>Setting up your store</h1></div>
            <div class="card-body">
                <?php if ($slow): ?>
                    <p>Setting up your store is taking longer than it should. We have been told and are trying again.</p>
                    <p class="mt-4">You can close this page. We will email you when your store is ready.</p>
                <?php else: ?>
                    <p>Your store is being set up. This takes about a minute.</p>
                    <p class="mt-4">This page checks again on its own. We will also email you a link to sign in.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
