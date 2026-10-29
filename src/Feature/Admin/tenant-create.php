<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $csrf */
/** @var string|null $error */
/** @var string $storeName */
/** @var string $email */
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Create store</h1>
        <p class="muted">Creates a mall and owner immediately. Email is marked verified — no confirmation link.</p>
    </div>
</div>
<form class="card mt-6" method="post" action="/admin/tenants/create">
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
    <div class="card-body stack">
        <?php if ($error !== null && $error !== ''): ?>
            <p role="alert"><?= View::text($error) ?></p>
        <?php endif; ?>
        <div class="field">
            <label for="store_name">Store name</label>
            <input class="input" id="store_name" name="store_name" required maxlength="120" value="<?= View::text($storeName) ?>">
        </div>
        <div class="field">
            <label for="email">Owner email</label>
            <input class="input" id="email" name="email" type="email" required maxlength="200" value="<?= View::text($email) ?>">
        </div>
        <div class="field">
            <label for="password">Password</label>
            <?php View::passwordField(id: 'password', name: 'password', required: true, minlength: 8, autocomplete: 'new-password'); ?>
        </div>
        <div class="field">
            <label for="password_confirm">Confirm password</label>
            <?php View::passwordField(id: 'password_confirm', name: 'password_confirm', required: true, minlength: 8, autocomplete: 'new-password'); ?>
        </div>
        <p class="row gap-2">
            <button class="btn btn-primary" type="submit">Create store</button>
            <a class="btn" href="/admin/tenants">Cancel</a>
        </p>
    </div>
</form>
