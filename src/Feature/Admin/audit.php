<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var list<array{email: string, action: string, outcome: string, slug: string, detail: string, at: string}> $events */
?>
<div class="card">
    <div class="card-head"><h1>Audit</h1></div>
    <div class="card-body">
        <?php if ($events === []): ?>
            <p class="muted">No audit events yet.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                <tr>
                    <th>When</th>
                    <th>Admin</th>
                    <th>Action</th>
                    <th>Outcome</th>
                    <th>Store</th>
                    <th>Detail</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($events as $event): ?>
                    <tr>
                        <td><?= View::text($event['at']) ?></td>
                        <td><?= View::text($event['email']) ?></td>
                        <td><code><?= View::text($event['action']) ?></code></td>
                        <td><?= View::text($event['outcome']) ?></td>
                        <td><?= View::text($event['slug']) ?></td>
                        <td class="text-sm muted"><?= View::text($event['detail']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
