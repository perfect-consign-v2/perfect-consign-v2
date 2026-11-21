<?php

declare(strict_types=1);

use PerfectConsign\Feature\Activity\ActivityFilter;
use PerfectConsign\Feature\Activity\ActivityPhrase;
use PerfectConsign\Shared\Http\View;

/** @var ActivityFilter $filter */
/** @var list<array{id: string, at: string, username: string, name: string, role: string, action: string, entity_type: string, entity_id: string, entity: string, authority: string, entered_by: string, address: string, recorded: bool, href: string}> $rows */
/** @var string|null $error */
/** @var string $slug */
/** @var string $export */
/** @var array{current: list<array{id: string, label: string}>, former: list<array{id: string, label: string}>} $people */
/** @var list<array{code: string, phrase: string}> $actions */
/** @var string|null $older */
/** @var string|null $newer */
/** @var int $total */
/** @var string $seller */

$open = $filter->from === null
    && $filter->to === null
    && $filter->accountId === null
    && $filter->action === null
    && $filter->before === null
    && $filter->after === null;
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Activity</h1>
        <p class="muted">Who signed in and what they changed.</p>
    </div>
    <a class="btn" href="/<?= View::text($slug) ?>/activity.csv<?= $export === '' ? '' : '?' . View::text($export) ?>">Download</a>
</div>
<form class="activity-filters" method="get" action="/<?= View::text($slug) ?>/activity">
    <div class="field">
        <label for="activity-from">From</label>
        <input id="activity-from" type="date" name="from" value="<?= View::text($filter->from ?? '') ?>">
    </div>
    <div class="field">
        <label for="activity-to">To</label>
        <input id="activity-to" type="date" name="to" min="<?= View::text($filter->from ?? '') ?>" value="<?= View::text($filter->to ?? '') ?>">
    </div>
    <div class="field">
        <label for="activity-account">Person</label>
        <select id="activity-account" name="account">
            <option value="">Everyone</option>
            <?php foreach ($people['current'] as $person): ?>
                <option value="<?= View::text($person['id']) ?>"<?= $filter->accountId === $person['id'] ? ' selected' : '' ?>><?= View::text($person['label']) ?></option>
            <?php endforeach; ?>
            <?php if ($people['former'] !== []): ?>
                <optgroup label="Former">
                    <?php foreach ($people['former'] as $person): ?>
                        <option value="<?= View::text($person['id']) ?>"<?= $filter->accountId === $person['id'] ? ' selected' : '' ?>><?= View::text($person['label']) ?></option>
                    <?php endforeach; ?>
                </optgroup>
            <?php endif; ?>
        </select>
    </div>
    <div class="field">
        <label for="activity-action">Action</label>
        <select id="activity-action" name="action">
            <option value="">Every action</option>
            <?php foreach ($actions as $choice): ?>
                <option value="<?= View::text($choice['code']) ?>"<?= $filter->action === $choice['code'] ? ' selected' : '' ?>><?= View::text($choice['phrase']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button class="btn btn-primary" type="submit">Show</button>
    <?php if ($filter->from !== null || $filter->to !== null || $filter->accountId !== null || $filter->action !== null || $filter->before !== null || $filter->after !== null): ?>
        <a class="btn" href="/<?= View::text($slug) ?>/activity">All activity</a>
    <?php endif; ?>
</form>
<?php if ($error !== null): ?>
    <p class="error"><?= View::text($error) ?></p>
<?php elseif ($rows === []): ?>
    <div class="card">
        <div class="empty">
            <p>No activity for that filter.</p>
            <?php if ($open): ?>
                <p>Sales, voids, staff changes, and register opens show up here.</p>
            <?php elseif ($filter->before !== null || $filter->after !== null): ?>
                <p>No rows on this page.</p>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
    <div class="card">
        <ol class="activity-log">
            <?php foreach ($rows as $row): ?>
                <?php
                $who = 'not recorded';
                if ($row['recorded']) {
                    $who = $row['name'] !== '' ? $row['name'] : $row['username'];
                }
                $role = match ($row['role']) {
                    'owner' => 'Owner',
                    'manager' => 'Manager',
                    'cashier' => 'Cashier',
                    'books' => 'Books',
                    'vendor' => $seller === 'consignor' ? 'Consignor' : 'Vendor',
                    default => $row['role'],
                };
                ?>
                <li>
                    <div class="activity-log__main">
                        <time><?= View::text(View::date($row['at'])) ?></time>
                        <strong><?= View::text($who) ?></strong>
                        <?php if ($role !== ''): ?>
                            <span class="badge"><?= View::text($role) ?></span>
                        <?php endif; ?>
                        <span><?= View::text(ActivityPhrase::text($row['action'], $seller)) ?></span>
                        <?php if ($row['href'] !== ''): ?>
                            <a href="<?= View::text($row['href']) ?>">Open</a>
                        <?php endif; ?>
                    </div>
                    <details>
                        <summary>Details</summary>
                        <dl>
                            <?php if ($row['recorded'] && $row['username'] !== ''): ?>
                                <dt>Username</dt>
                                <dd><?= View::text($row['username']) ?></dd>
                            <?php endif; ?>
                            <dt>Action</dt>
                            <dd><code><?= View::text($row['action']) ?></code></dd>
                            <?php if ($row['entity'] !== ''): ?>
                                <dt>Record</dt>
                                <dd><?= View::text($row['entity']) ?></dd>
                            <?php endif; ?>
                            <?php if ($row['authority'] !== ''): ?>
                                <dt>Second signer</dt>
                                <dd><?= View::text($row['authority']) ?></dd>
                            <?php endif; ?>
                            <?php if ($row['entered_by'] !== ''): ?>
                                <dt>Entered as</dt>
                                <dd><?= View::text($row['entered_by']) ?></dd>
                            <?php endif; ?>
                            <?php if ($row['address'] !== ''): ?>
                                <dt>Address</dt>
                                <dd><?= View::text($row['address']) ?></dd>
                            <?php endif; ?>
                        </dl>
                    </details>
                </li>
            <?php endforeach; ?>
        </ol>
    </div>
<?php endif; ?>
<?php if ($error === null && $total > 0): ?>
    <p class="activity-pager">
        Showing <?= View::text((string) count($rows)) ?> of <?= View::text((string) $total) ?>.
        <?php if (is_string($older) && $older !== ''): ?>
            <a href="/<?= View::text($slug) ?>/activity?<?= View::text($filter->pageQuery('before', $older)) ?>">Older</a>
        <?php endif; ?>
        <?php if (is_string($newer) && $newer !== ''): ?>
            <a href="/<?= View::text($slug) ?>/activity?<?= View::text($filter->pageQuery('after', $newer)) ?>">Newer</a>
        <?php endif; ?>
    </p>
<?php endif; ?>
