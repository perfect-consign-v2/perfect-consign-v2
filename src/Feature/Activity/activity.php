<?php

declare(strict_types=1);

use PerfectConsign\Feature\Activity\ActivityFilter;
use PerfectConsign\Shared\Http\View;

/** @var ActivityFilter $filter */
/** @var list<array{at: string, username: string, name: string, role: string, action: string, entity: string, authority: string, entered_by: string, address: string, recorded: bool}> $rows */
/** @var string|null $error */
/** @var string $slug */
/** @var string $export */
?>
<h1>Activity</h1>
<div class="card">
    <div class="card-body">
        <form method="get" action="/<?= View::text($slug) ?>/activity">
            <label>From <input type="date" name="from" value="<?= View::text($filter->from ?? '') ?>"></label>
            <label>To <input type="date" name="to" min="<?= View::text($filter->from ?? '') ?>" value="<?= View::text($filter->to ?? '') ?>"></label>
            <label>Account <input name="account" value="<?= View::text($filter->accountId ?? '') ?>"></label>
            <label>Action <input name="action" value="<?= View::text($filter->action ?? '') ?>"></label>
            <button type="submit">Show</button>
            <a href="/<?= View::text($slug) ?>/activity.csv<?= $export === '' ? '' : '?' . View::text($export) ?>">Download</a>
        </form>
        <?php if ($error !== null): ?>
            <p class="error"><?= View::text($error) ?></p>
        <?php endif; ?>
        <?php if ($rows === []): ?>
            <p class="muted">No activity for that filter.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th>When</th>
                        <th>Username</th>
                        <th>Name</th>
                        <th>Role</th>
                        <th>Action</th>
                        <th>Entity</th>
                        <th>Authority</th>
                        <th>Enter as</th>
                        <th>Address</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= View::text($row['at']) ?></td>
                            <td><?= View::text($row['recorded'] ? $row['username'] : 'not recorded') ?></td>
                            <td><?= View::text($row['name']) ?></td>
                            <td><?= View::text($row['role']) ?></td>
                            <td><code><?= View::text($row['action']) ?></code></td>
                            <td><?= View::text($row['entity']) ?></td>
                            <td><?= View::text($row['authority']) ?></td>
                            <td><?= View::text($row['entered_by']) ?></td>
                            <td><?= View::text($row['address']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
