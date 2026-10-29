<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $csrf */
/** @var bool $enabled */
/** @var bool $requireVerification */
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Store signup</h1>
        <p class="muted">When on, anyone can open Create your store at /signup. Default is off. Email confirmation defaults on.</p>
    </div>
</div>
<form class="card mt-6" method="post" action="/admin/signup">
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
    <div class="card-body stack">
        <label class="row gap-2">
            <input type="checkbox" name="store_signup_enabled" value="1"<?= $enabled ? ' checked' : '' ?>>
            Allow public store signup
        </label>
        <label class="row gap-2">
            <input type="checkbox" name="store_signup_require_email_verification" value="1"<?= $requireVerification ? ' checked' : '' ?>>
            Require email confirmation before the owner can sign in
        </label>
        <p class="muted text-sm">Admin-created stores always skip email confirmation. Operators can still create a store from Tenants when public signup is off.</p>
        <p><button class="btn btn-primary" type="submit">Save</button></p>
    </div>
</form>
