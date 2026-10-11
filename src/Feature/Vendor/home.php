<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var array{
 *     name: string,
 *     hold: bool,
 *     open: bool,
 *     payable: int,
 *     rent_label: string,
 *     rent: int,
 *     check: int,
 *     desk: int,
 *     booth: array{code: string, rent: int|null, note: string}|null,
 *     others: list<array{code: string, starts_on: string, ends_on: string, rent: int|null}>,
 *     activity: list<array{date: string, label: string, amount: int, run_id: string}>,
 *     last: array{run_id: string, paid_on: string, amount: int, number: string}|null
 * } $page
 */
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Your check</h1>
        <p class="muted"><?= View::text($page['name']) ?></p>
    </div>
</div>
<?php if ($page['hold']): ?>
    <p class="mt-4">The store is not writing a check. Pay rent at the desk.</p>
<?php endif; ?>
<?php if (!$page['open']): ?>
    <p class="mt-4">No check is open.</p>
<?php endif; ?>
<div class="payout-figures">
    <div class="stat">
        <div class="stat-label">Your share of sales</div>
        <div class="stat-value" id="check-share"><?= View::text(View::money($page['payable'])) ?></div>
    </div>
    <div class="stat">
        <div class="stat-label"><?= View::text($page['rent_label']) ?></div>
        <div class="stat-value"><?= View::text(View::money($page['rent'])) ?></div>
    </div>
    <div class="stat payout-check">
        <div class="stat-label">Your check</div>
        <div class="stat-value"><?= View::text(View::money($page['check'])) ?></div>
    </div>
</div>
<?php if ($page['desk'] > 0): ?>
    <p class="mt-4">You still owe <?= View::text(View::money($page['desk'])) ?> at the desk.</p>
<?php endif; ?>
<?php if ($page['last'] === null): ?>
    <p class="muted mt-4">No check has been written.</p>
<?php else: ?>
    <p class="mt-4">
        <a href="/<?= View::text($slug) ?>/vendor/checks/<?= View::text($page['last']['run_id']) ?>">
            Last check <?= View::text($page['last']['paid_on']) ?>
            <?= View::text(View::money($page['last']['amount'])) ?>
        </a>
        <?php if ($page['last']['number'] !== ''): ?>
            <span class="muted">No. <?= View::text($page['last']['number']) ?></span>
        <?php endif; ?>
    </p>
<?php endif; ?>
<section class="card mt-6">
    <div class="card-head"><div class="h3">Booth</div></div>
    <div class="card-body">
        <?php if ($page['booth'] === null): ?>
            <p class="muted">No booth.</p>
        <?php else: ?>
            <p><?= View::text($page['booth']['code']) ?>
                <?php if ($page['booth']['rent'] !== null): ?>
                    · <?= View::text(View::money($page['booth']['rent'])) ?>
                <?php endif; ?>
            </p>
            <?php if ($page['booth']['note'] !== ''): ?>
                <p class="muted"><?= View::text($page['booth']['note']) ?></p>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($page['others'] !== []): ?>
            <ul class="mt-4">
                <?php foreach ($page['others'] as $stay): ?>
                    <li>
                        <?= View::text($stay['code']) ?>
                        <?= View::text($stay['starts_on']) ?>
                        <?php if ($stay['ends_on'] !== ''): ?>
                            to <?= View::text($stay['ends_on']) ?>
                        <?php endif; ?>
                        <?php if ($stay['rent'] !== null): ?>
                            <?= View::text(View::money($stay['rent'])) ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</section>
<section class="card table-wrap mt-6">
    <div class="card-head"><div class="h3">Activity</div></div>
    <?php if ($page['activity'] === []): ?>
        <div class="card-body"><p class="muted">Nothing posted yet.</p></div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>What</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($page['activity'] as $line): ?>
                <tr>
                    <td><?= View::text($line['date']) ?></td>
                    <td>
                        <?php if ($line['run_id'] !== ''): ?>
                            <a href="/<?= View::text($slug) ?>/vendor/checks/<?= View::text($line['run_id']) ?>"><?= View::text($line['label']) ?></a>
                        <?php else: ?>
                            <?= View::text($line['label']) ?>
                        <?php endif; ?>
                    </td>
                    <td<?= $line['label'] === 'Bought at the store' ? ' class="draw-amount"' : '' ?>><?= View::text(View::money($line['amount'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
