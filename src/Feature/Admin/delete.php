<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Tenant\TenantRecord;

/** @var string $csrf */
/** @var TenantRecord $tenant */
/** @var bool $requiresCountersign */
$requiresCountersign = ($requiresCountersign ?? true) === true;
?>
<h1>Delete <?= View::text($tenant->displayName) ?></h1>
<div class="card" data-style-prop="max-width:36rem">
    <form method="post" action="/admin/tenants/<?= View::text($tenant->slug) ?>/delete">
        <div class="card-body grid" data-style-prop="gap:var(--space-4)">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <div class="alert alert-danger">
                <p data-style-prop="margin:0 0 var(--space-2)"><strong>This permanently deletes the store.</strong></p>
                <p data-style-prop="margin:0">The tenant schema, poster role, owners, and store data are removed. This cannot be undone. Backup metadata, password-reset history, and email-confirmation history may remain on the platform.</p>
            </div>
            <?php if ($requiresCountersign) { ?>
            <p>Type the slug <code><?= View::text($tenant->slug) ?></code> to confirm. A second platform admin must sign, and an owner backup from the last seven days is required.</p>
            <?php } else { ?>
            <p>Type the slug <code><?= View::text($tenant->slug) ?></code> to confirm.</p>
            <?php } ?>
            <div class="field">
                <label for="confirm_slug">Slug</label>
                <input class="input" id="confirm_slug" name="confirm_slug" autocomplete="off" required>
            </div>
            <?php if ($requiresCountersign) { ?>
            <div class="field">
                <label for="second_email">Second admin email</label>
                <input class="input" id="second_email" name="second_email" type="email" autocomplete="username" required>
            </div>
            <div class="field">
                <label for="second_password">Second admin password</label>
                <input class="input" id="second_password" name="second_password" type="password" autocomplete="current-password" required>
            </div>
            <?php } ?>
        </div>
        <div class="card-foot row between">
            <a href="/admin/tenants">Cancel</a>
            <button class="btn btn-danger" type="submit">Delete permanently</button>
        </div>
    </form>
</div>
