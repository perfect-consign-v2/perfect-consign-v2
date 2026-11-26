<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $csrf */
/** @var \PerfectConsign\Feature\Admin\AdminPath $adminPath */
/** @var bool $enabled */
/** @var list<array{id: string, email: string, store_name: string, status: string, created_at: string, expires_at: string, slug: string, last_error: string, by_operator: bool}> $signups */
/** @var int $reaped */
/** @var int $pendingHours */
/** @var list<array{slug: string, display_name: string, email: string, created_at: string}> $unconfirmed */
$labels = [
    'pending' => 'Waiting for the owner to open the link',
    'verified' => 'Confirmed, waiting to be built',
    'provisioning' => 'Being built',
    'failed' => 'Build failed, trying again',
    'ready' => 'Ready',
];
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Signups</h1>
        <p class="muted">A signup builds nothing until the owner opens the emailed link. Then the worker builds the store.</p>
    </div>
</div>
<form class="card mt-6" method="post" action="<?= View::text($adminPath->url('/signup')) ?>">
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
    <div class="card-body stack">
        <label class="row gap-2">
            <input type="checkbox" name="store_signup_enabled" value="1"<?= $enabled ? ' checked' : '' ?>>
            Allow public store signup at /signup
        </label>
        <p class="muted text-sm">Operators can still create a store from Tenants when public signup is off.</p>
        <p><button class="btn btn-primary" type="submit">Save</button></p>
    </div>
</form>
<div class="card mt-6">
    <div class="card-head"><div class="h3">Signups</div></div>
    <div class="card-body">
        <p class="muted text-sm">
            A link works for <?= View::text((string) $pendingHours) ?> hours. Signups whose link ran out are removed each day.
            <?= View::text((string) $reaped) ?> were removed in the last 30 days.
        </p>
        <?php if ($signups === []): ?>
            <p class="muted">No signups.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>Store</th>
                        <th>Email</th>
                        <th>Asked</th>
                        <th>Where it stands</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($signups as $signup): ?>
                        <tr>
                            <td>
                                <?= View::text($signup['store_name']) ?>
                                <?php if ($signup['slug'] !== ''): ?>
                                    <br><code><?= View::text($signup['slug']) ?></code>
                                <?php endif; ?>
                            </td>
                            <td><?= View::text($signup['email']) ?></td>
                            <td>
                                <?= View::text($signup['created_at']) ?>
                                <?php if ($signup['by_operator']): ?>
                                    <br><span class="muted text-sm">by an operator</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= View::text($labels[$signup['status']] ?? $signup['status']) ?>
                                <?php if ($signup['status'] === 'pending'): ?>
                                    <br><span class="muted text-sm">Link works until <?= View::text($signup['expires_at']) ?></span>
                                <?php endif; ?>
                                <?php if ($signup['last_error'] !== ''): ?>
                                    <br><span class="muted text-sm"><?= View::text($signup['last_error']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="row gap-2" data-style-prop="justify-content:flex-end; flex-wrap:wrap">
                                    <?php if ($signup['status'] === 'pending'): ?>
                                        <form method="post" action="<?= View::text($adminPath->url('/signup/resend')) ?>">
                                            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                            <input type="hidden" name="signup_id" value="<?= View::text($signup['id']) ?>">
                                            <button class="btn btn-sm" type="submit">Resend link</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if (in_array($signup['status'], ['pending', 'verified', 'failed'], true)): ?>
                                        <form method="post" action="<?= View::text($adminPath->url('/signup/discard')) ?>">
                                            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                            <input type="hidden" name="signup_id" value="<?= View::text($signup['id']) ?>">
                                            <button class="btn btn-sm btn-danger" type="submit">Discard</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<div class="card mt-6">
    <div class="card-head"><div class="h3">Stores whose owner never confirmed the email</div></div>
    <div class="card-body">
        <p class="muted text-sm">These were built before signup waited for the link. Nothing deletes them on its own. Delete one the same way as any store.</p>
        <?php if ($unconfirmed === []): ?>
            <p class="muted">None.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>Store</th>
                        <th>Owner email</th>
                        <th>Created</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($unconfirmed as $store): ?>
                        <tr>
                            <td><?= View::text($store['display_name']) ?><br><code><?= View::text($store['slug']) ?></code></td>
                            <td><?= View::text($store['email']) ?></td>
                            <td><?= View::text($store['created_at']) ?></td>
                            <td>
                                <a class="btn btn-sm btn-danger" href="<?= View::text($adminPath->url('/tenants/' . $store['slug'] . '/delete')) ?>">Delete store</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
