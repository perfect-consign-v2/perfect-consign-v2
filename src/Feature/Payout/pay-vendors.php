<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Web\ListTable;

/** @var string $slug */
/** @var string $csrf */
/** @var string $displayName */
/** @var string $monthName */
/** @var list<array{vendor_id: string, code: string, name: string, booth: string, boothId: string, payable: int, monthRent: int, check: int, owes: int, note?: string}> $pack */
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
$consignmentOnly = ($consignmentOnly ?? false) === true;
$words = ['credit_only' => 'store credit only', 'hold' => 'hold'];
$paid = $savedStatus === 'paid';
$heading = $monthName . ' payout';
if ($paid && $savedTender === 'check' && $checks === 0) {
    $heading = $monthName . ' is paid by check. Nobody is getting a check.';
} elseif ($paid && $savedTender === 'check') {
    $heading = $monthName . ' is paid by check. ' . $checks . ' ' . ($checks === 1 ? 'check' : 'checks') . ', ' . View::money($checkTotal) . '.';
} elseif ($paid && $savedTender === 'drawer_cash') {
    $heading = $monthName . ' is paid from the drawer. ' . View::money($checkTotal) . ' went out.';
} elseif ($paid && $savedTender === 'safe_cash') {
    $heading = $monthName . ' is paid from the safe. ' . View::money($checkTotal) . ' went out.';
} elseif ($paid) {
    $heading = $monthName . ' is paid.';
}
?>
<div class="payout-sheet">
<div class="page-head">
    <div class="titles">
        <h1 class="h1"><?= View::text($heading) ?></h1>
        <p class="muted"><?= View::text($displayName) ?></p>
    </div>
    <a class="btn no-print" href="/<?= View::text($slug) ?>/payouts/past">Past payouts</a>
</div>
<div class="card list-dt-wrap">
    <div class="card-body">
        <?php
        ListTable::open(['id' => 'pay-vendors', 'export' => 'pay-vendors', 'order' => [[0, 'asc']], 'fit' => true]);
        $columns = [
            ['label' => $consignmentOnly ? 'Consignor' : 'Vendor', 'token' => 'id'],
        ];
        if (!$consignmentOnly) {
            $columns[] = ['label' => 'Booth'];
        }
        $columns[] = ['label' => 'They earned', 'sort' => 'num'];
        if (!$consignmentOnly) {
            $columns[] = ['label' => 'Rent', 'sort' => 'num'];
        }
        $columns[] = ['label' => $paid ? 'Paid them' : 'Pay them', 'sort' => 'num'];
        if (!$consignmentOnly) {
            $columns[] = ['label' => 'Owes the store', 'sort' => 'num'];
        }
        ListTable::head($columns);
        ?>
            <tbody>
            <?php foreach ($pack as $row): ?>
                <tr>
                    <td>
                        <a class="strong" href="/<?= View::text($slug) ?>/vendors/<?= View::text($row['vendor_id']) ?>">(<?= View::text($row['code']) ?>) <?= View::text($row['name']) ?></a>
                        <?php if (($row['note'] ?? '') !== ''): ?><p class="muted"><?= View::text($row['note']) ?></p><?php endif; ?>
                    </td>
                    <?php if (!$consignmentOnly): ?>
                    <td><?= View::text($row['booth'] === '' ? '—' : $row['booth']) ?></td>
                    <?php endif; ?>
                    <td><?= View::text(View::money($row['payable'])) ?></td>
                    <?php if (!$consignmentOnly): ?>
                    <td><?= View::text(View::money($row['monthRent'])) ?></td>
                    <?php endif; ?>
                    <td><?php if ($paid && $row['check'] > 0): ?>Paid <?= View::text(View::money($row['check'])) ?><?php elseif ($paid): ?>No payment<?php else: ?><span class="payout-due"><?= View::text(View::money($row['check'])) ?></span><?php endif; ?></td>
                    <?php if (!$consignmentOnly): ?>
                    <td><?php if ($row['owes'] > 0): ?><a class="owe-amount" href="/<?= View::text($slug) ?>/rent/collect?vendor=<?= View::text($row['vendor_id']) ?>">(<?= View::text(View::money($row['owes'])) ?>)</a><?php endif; ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$consignmentOnly): ?>
        <p>Rent comes out of what they earned.</p>
        <?php endif; ?>
        <p class="muted"><?= View::text((string) $checks) ?> <?= $savedStatus === 'paid' ? 'paid' : 'to pay' ?>, <?= View::text(View::money($checkTotal)) ?>. <?= View::text((string) $owesCount) ?> owe the store, <?= View::text(View::money($owesTotal)) ?>.</p>
    </div>
</div>
<?php if ($excluded !== []): ?>
    <p class="muted">Left off this payout: <?php foreach ($excluded as $vendor): ?><?= View::text($vendor['name']) ?> (<?= View::text($words[$vendor['mode']] ?? $vendor['mode']) ?>) <?php endforeach; ?></p>
<?php endif; ?>
<?php if ($savedStatus === 'settled'): ?>
    <p class="strong"><?= View::text($monthName) ?> is saved, and the payment was not recorded.</p>
<?php endif; ?>
</div>
<div class="stack mt-6 no-print payout-next">
    <?php if ($savedRun !== '' && $savedTender === 'check' && $paid): ?>
        <a class="btn btn-primary" href="/<?= View::text($slug) ?>/payouts/checks?run=<?= View::text($savedRun) ?>">Print checks</a>
        <a class="text-link" href="/<?= View::text($slug) ?>/payouts/report?run=<?= View::text($savedRun) ?>">Print each <?= $consignmentOnly ? 'consignor' : 'vendor' ?>'s sales</a>
        <button class="text-link" type="button" data-print>Print this list</button>
    <?php elseif ($savedRun !== ''): ?>
        <button class="btn" type="button" data-print>Print this list</button>
        <a class="text-link" href="/<?= View::text($slug) ?>/payouts/report?run=<?= View::text($savedRun) ?>">Print each <?= $consignmentOnly ? 'consignor' : 'vendor' ?>'s sales</a>
    <?php else: ?>
        <button class="btn" type="button" data-print>Print this list</button>
        <form class="pay-vendors" method="post" action="/<?= View::text($slug) ?>/payouts/close">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <label for="tender">Pay from</label>
            <select class="input" id="tender" name="tender">
                <option value="check" selected>Check</option>
                <option value="drawer_cash">Cash from the drawer</option>
                <option value="safe_cash">Cash from the safe</option>
            </select>
            <label for="override_user">Second person</label>
            <input class="input" id="override_user" name="override_user" autocomplete="off">
            <label for="override_password">Their password</label>
            <?php View::passwordField(id: 'override_password', name: 'override_password', autocomplete: 'off'); ?>
            <button class="btn btn-primary" type="submit">Pay these <?= $consignmentOnly ? 'consignors' : 'vendors' ?></button>
        </form>
    <?php endif; ?>
</div>
<?php ListTable::assets(); ?>
<script src="/assets/js/print-trigger.js"></script>
<script src="/assets/js/pay-vendors-print.js"></script>
