<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $csrf */
/** @var list<array{id: string, slug: string, displayName: string, mode: string, modeLabel: string}> $tenants */
?>
<h1>Tenants</h1>
<div class="card">
    <div class="card-head row between">
        <div class="h3">Stores</div>
        <div class="row gap-2">
            <a class="btn btn-sm btn-primary" href="/admin/tenants/create">Create store</a>
            <span class="muted text-sm"><?= View::text((string) count($tenants)) ?> total</span>
        </div>
    </div>
    <div class="card-body">
        <?php if ($tenants === []): ?>
            <p class="muted">No tenants yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>Store</th>
                        <th>Slug</th>
                        <th>Kind</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($tenants as $tenant): ?>
                        <tr>
                            <td><?= View::text($tenant['displayName']) ?></td>
                            <td><code><?= View::text($tenant['slug']) ?></code></td>
                            <td><?= View::text($tenant['modeLabel']) ?></td>
                            <td>
                                <div class="row gap-2" data-style-prop="justify-content:flex-end; flex-wrap:wrap">
                                    <form method="post" action="/admin/tenants/<?= View::text($tenant['slug']) ?>/enter">
                                        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                        <input type="hidden" name="role" value="owner">
                                        <button class="btn btn-sm" type="submit">Enter as owner</button>
                                    </form>
                                    <form method="post" action="/admin/tenants/<?= View::text($tenant['slug']) ?>/enter">
                                        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                        <input type="hidden" name="role" value="cashier">
                                        <button class="btn btn-sm" type="submit">Enter as cashier</button>
                                    </form>
                                    <a class="btn btn-sm btn-danger" href="/admin/tenants/<?= View::text($tenant['slug']) ?>/delete">Delete</a>
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
