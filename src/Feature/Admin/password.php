<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $csrf */
?>
<h1>Change password</h1>
<div class="card" data-style-prop="max-width:28rem">
    <form method="post" action="/admin/password">
        <div class="card-body grid" data-style-prop="gap:var(--space-4)">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <div class="field">
                <label for="current">Current password</label>
                <input class="input" id="current" name="current" type="password" autocomplete="current-password" required>
            </div>
            <div class="field">
                <label for="password">New password</label>
                <input class="input" id="password" name="password" type="password" autocomplete="new-password" required minlength="8">
            </div>
            <div class="field">
                <label for="confirm">Confirm password</label>
                <input class="input" id="confirm" name="confirm" type="password" autocomplete="new-password" required minlength="8">
            </div>
        </div>
        <div class="card-foot">
            <button class="btn btn-primary" type="submit">Save password</button>
        </div>
    </form>
</div>
