<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Web\ListTable;

/** @var string $slug */
/** @var string $csrf */
/** @var string $displayName */
/** @var string $monthName */
/** @var list<array{vendor_id: string, code: string, name: string, booth: string, prior: int, charged: int, paid: int, payable: int, rent: int, check: int, owes: int}> $pack */
/** @var list<array{code: string, name: string, mode: string}> $excluded */
/** @var string $savedRun */
/** @var string $savedTender */
/** @var string $savedStatus */
/** @var string|null $error */
$savedRun = $savedRun ?? '';
$savedTender = $savedTender ?? '';
$savedStatus = $savedStatus ?? '';

$checks = 0;
$checkTotal = 0;
$owesCount = 0;
$owesTotal = 0;
foreach ($pack as $row) {
    if ($row['check'] > 0) {
        $checks++;
        $checkTotal += $row['check'];
    }
    if ($row['owes'] > 0) {
        $owesCount++;
        $owesTotal += $row['owes'];
    }
}
$words = ['credit_only' => 'store credit only', 'hold' => 'hold'];
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1"><?= View::text($monthName) ?> payout</h1>
        <p class="muted"><?= View::text($displayName) ?></p>
    </div>
    <a class="btn no-print" href="/<?= View::text($slug) ?>/payouts/past">Past payouts</a>
</div>
<?php if (is_string($error) && $error !== ''): ?>
    <p class="alert"><?= View::text($error) ?></p>
<?php endif; ?>
<div class="card list-dt-wrap">
    <div class="card-body">
        <?php
        ListTable::open(['id' => 'pay-vendors', 'export' => 'pay-vendors', 'order' => [[0, 'asc']]]);
        ListTable::head([
            ['label' => 'Vendor', 'token' => 'id'],
            ['label' => 'Booth'],
            ['label' => 'Check', 'sort' => 'num'],
            ['label' => 'Their share', 'sort' => 'num'],
            ['label' => 'Owed before', 'sort' => 'num'],
            ['label' => 'This month', 'sort' => 'num'],
            ['label' => 'Paid since', 'sort' => 'num'],
            ['label' => 'Owes the store', 'sort' => 'num'],
        ]);
        ?>
            <tbody>
            <?php foreach ($pack as $row): ?>
                <tr>
                    <td><a class="strong" href="/<?= View::text($slug) ?>/vendors/<?= View::text($row['vendor_id']) ?>">(<?= View::text($row['code']) ?>) <?= View::text($row['name']) ?></a></td>
                    <td><?= View::text($row['booth'] === '' ? '—' : $row['booth']) ?></td>
                    <td style="font-size:1.35rem;font-weight:700"><?= View::text(View::money($row['check'])) ?></td>
                    <td><?= View::text(View::money($row['payable'])) ?></td>
                    <td><?= View::text(View::money($row['prior'])) ?></td>
                    <td><?= View::text(View::money($row['charged'])) ?></td>
                    <td><?= View::text(View::money($row['paid'])) ?></td>
                    <td><?php if ($row['owes'] > 0): ?><span class="text-danger"><?= View::text(View::money($row['owes'])) ?></span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="muted"><?= View::text((string) $checks) ?> <?= $savedStatus === 'paid' ? 'paid' : 'to pay' ?>, <?= View::text(View::money($checkTotal)) ?>. <?= View::text((string) $owesCount) ?> owe the store, <?= View::text(View::money($owesTotal)) ?>.</p>
    </div>
</div>
<?php if ($excluded !== []): ?>
    <p class="muted">Left off this payout: <?php foreach ($excluded as $vendor): ?><?= View::text($vendor['name']) ?> (<?= View::text($words[$vendor['mode']] ?? $vendor['mode']) ?>) <?php endforeach; ?></p>
<?php endif; ?>
<?php if ($savedStatus === 'paid'): ?>
    <p class="strong"><?= View::text($monthName) ?> is paid.</p>
<?php elseif ($savedStatus === 'settled'): ?>
    <p class="strong"><?= View::text($monthName) ?> is saved, and the payment was not recorded.</p>
<?php endif; ?>
<div class="stack mt-6 no-print">
    <button class="btn" type="button" onclick="window.print()">Print this list</button>
    <?php if ($savedRun !== ''): ?>
        <a class="btn" href="/<?= View::text($slug) ?>/payouts/report?run=<?= View::text($savedRun) ?>">Print each vendor's sales</a>
        <?php if ($savedTender === 'check' && $savedStatus === 'paid'): ?>
            <a class="btn" href="/<?= View::text($slug) ?>/payouts/checks?run=<?= View::text($savedRun) ?>">Print checks</a>
        <?php endif; ?>
    <?php else: ?>
        <form class="pay-vendors" method="post" action="/<?= View::text($slug) ?>/payouts/close">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <label for="tender">Pay from</label>
            <select class="input" id="tender" name="tender">
                <option value="check" selected>Check</option>
                <option value="drawer_cash">Cash from the drawer</option>
                <option value="safe_cash">Cash from the safe</option>
            </select>
            <div class="when-check">
                <label for="starting_check">First check number</label>
                <input class="input" id="starting_check" name="starting_check" inputmode="numeric">
                <p class="muted text-sm">The number on the first check. The next checks count up from here.</p>
            </div>
            <button class="btn btn-primary" type="submit">Pay these vendors</button>
        </form>
        <style>
            .pay-vendors .when-check { display: none; }
            .pay-vendors:has(#tender option[value="check"]:checked) .when-check { display: block; }
        </style>
    <?php endif; ?>
</div>
<?php ListTable::assets(); ?>
