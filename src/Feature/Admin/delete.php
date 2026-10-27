<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Tenant\TenantRecord;

/** @var string $csrf */
/** @var TenantRecord $tenant */
?>
<h1>Delete <?= View::text($tenant->displayName) ?></h1>
<div class="card" data-style-prop="max-width:36rem">
    <form method="post" action="/admin/tenants/<?= View::text($tenant->slug) ?>/delete">
        <div class="card-body grid" data-style-prop="gap:var(--space-4)">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <div class="alert alert-danger">
                <p data-style-prop="margin:0 0 var(--space-2)"><strong>This permanently deletes the store.</strong></p>
                <p data-style-prop="margin:0">The tenant schema, poster role, owners, and store data are removed. This cannot be undone. Backup metadata rows may remain on the platform.</p>
            </div>
            <p>Type the slug <code><?= View::text($tenant->slug) ?></code> to confirm.</p>
            <div class="field">
                <label for="confirm_slug">Slug</label>
                <input class="input" id="confirm_slug" name="confirm_slug" autocomplete="off" required>
            </div>
        </div>
        <div class="card-foot row between">
            <a href="/admin/tenants">Cancel</a>
            <button class="btn btn-danger" type="submit">Delete permanently</button>
        </div>
    </form>
</div>
