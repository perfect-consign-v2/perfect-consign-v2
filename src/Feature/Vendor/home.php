<?php

declare(strict_types=1);

use PerfectConsign\Feature\Vendor\VendorPreview;
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
 *     last: array{run_id: string, paid_on: string, amount: int, number: string}|null,
 *     consignor: array{sold: int, take: int, owed: int, expired: int},
 *     dealer: array{sold: int, owed: int, rent: int, settled: int}
 * } $page
 */

$previewAs = is_string($previewAs ?? null) ? $previewAs : '';
$checkLink = static function (string $runId) use ($slug, $previewAs): string {
    return VendorPreview::with('/' . $slug . '/vendor/checks/' . $runId, $previewAs);
};
$consignor = is_array($page['consignor'] ?? null) ? $page['consignor'] : ['sold' => 0, 'take' => 0, 'owed' => 0, 'expired' => 0];
$dealer = is_array($page['dealer'] ?? null) ? $page['dealer'] : ['sold' => 0, 'owed' => 0, 'rent' => 0, 'settled' => 0];
?>
<?php if ($consignor['sold'] > 0 || $consignor['expired'] > 0): ?>
    <p class="mt-4">Consignment: sold <?= View::text(View::money($consignor['sold'])) ?>, store take <?= View::text(View::money($consignor['take'])) ?>, owed <?= View::text(View::money($consignor['owed'])) ?>, expired still out <?= View::text((string) $consignor['expired']) ?>.</p>
<?php endif; ?>
<?php if ($dealer['sold'] > 0 || $dealer['rent'] !== 0): ?>
    <p>Booth: sold <?= View::text(View::money($dealer['sold'])) ?>, owed <?= View::text(View::money($dealer['owed'])) ?>, rent <?= View::text(View::money($dealer['rent'])) ?>.</p>
<?php endif; ?>
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
    <?php
    $cycleDay = is_int($cycleDay ?? null) ? $cycleDay : 1;
    $when = $cycleDay === 1
        ? 'The mall writes checks on the first day of the month.'
        : 'The mall writes checks on day ' . (string) $cycleDay . ' of the rent cycle.';
    ?>
    <p class="mt-4"><?= View::text($when) ?> Your share of sales is what you have earned and not been paid. Rent is what is still held for the booth. Your check is the share after that rent.</p>
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
        <a href="<?= View::text($checkLink($page['last']['run_id'])) ?>">
            Last check <?= View::date($page['last']['paid_on']) ?>
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
                        <?= View::date($stay['starts_on']) ?>
                        <?php if ($stay['ends_on'] !== ''): ?>
                            to <?= View::date($stay['ends_on']) ?>
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
                    <td><?= View::date($line['date']) ?></td>
                    <td>
                        <?php if ($line['run_id'] !== ''): ?>
                            <a href="<?= View::text($checkLink($line['run_id'])) ?>"><?= View::text($line['label']) ?></a>
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
