<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var string $displayName */
/** @var string $monthName */
/** @var list<array{vendor_id: string, code: string, name: string, booth: string, prior: int, charged: int, paid: int, payable: int, rent: int, check: int, owes: int}> $pack */
/** @var list<array{code: string, name: string, mode: string}> $excluded */
/** @var list<array<string, mixed>> $runs */
/** @var string|null $error */

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
$latest = $runs[0]['id'] ?? '';
$runId = is_string($latest) ? $latest : '';
$words = ['credit_only' => 'store credit only', 'hold' => 'hold'];
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1"><?= View::text($monthName) ?> payout</h1>
        <p class="muted"><?= View::text($displayName) ?></p>
    </div>
    <a class="btn btn-ghost no-print" href="/<?= View::text($slug) ?>/payouts/past">Past payouts</a>
</div>
<?php if (is_string($error) && $error !== ''): ?>
    <p class="alert"><?= View::text($error) ?></p>
<?php endif; ?>
<div class="card">
    <div class="card-body">
        <table class="table">
            <thead>
                <tr>
                    <th>Vendor</th>
                    <th>Booth</th>
                    <th>Their share</th>
                    <th>Owed before</th>
                    <th>This month</th>
                    <th>Paid since</th>
                    <th>Check</th>
                    <th>Owes the store</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($pack as $row): ?>
                <tr>
                    <td><?= View::text($row['name']) ?> <span class="muted"><?= View::text($row['code']) ?></span></td>
                    <td><?= View::text($row['booth'] === '' ? '—' : $row['booth']) ?></td>
                    <td><?= View::text(View::money($row['payable'])) ?></td>
                    <td><?= View::text(View::money($row['prior'])) ?></td>
                    <td><?= View::text(View::money($row['charged'])) ?></td>
                    <td><?= View::text(View::money($row['paid'])) ?></td>
                    <td><?= $row['check'] > 0 ? View::text(View::money($row['check'])) : '' ?></td>
                    <td><?php if ($row['owes'] > 0): ?><span class="text-danger"><?= View::text(View::money($row['owes'])) ?></span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="muted"><?= View::text((string) $checks) ?> checks, <?= View::text(View::money($checkTotal)) ?>. <?= View::text((string) $owesCount) ?> owe the store, <?= View::text(View::money($owesTotal)) ?>.</p>
    </div>
</div>
<?php foreach ($pack as $row): ?>
    <section class="vendor-sheet">
        <h2><?= View::text($row['name']) ?></h2>
        <p><?= View::text($row['booth'] === '' ? 'No booth' : $row['booth']) ?></p>
        <p>Their share <?= View::text(View::money($row['payable'])) ?></p>
        <p>Owed before <?= View::text(View::money($row['prior'])) ?></p>
        <p>This month <?= View::text(View::money($row['charged'])) ?></p>
        <?php if ($row['check'] > 0): ?>
            <p>Check <?= View::text(View::money($row['check'])) ?></p>
        <?php endif; ?>
        <?php if ($row['owes'] > 0): ?>
            <p>Owes the store <?= View::text(View::money($row['owes'])) ?></p>
        <?php endif; ?>
    </section>
<?php endforeach; ?>
<?php if ($excluded !== []): ?>
    <p class="muted">Left off this payout: <?php foreach ($excluded as $vendor): ?><?= View::text($vendor['name']) ?> (<?= View::text($words[$vendor['mode']] ?? $vendor['mode']) ?>) <?php endforeach; ?></p>
<?php endif; ?>
<div class="stack mt-6 no-print">
    <button class="btn" type="button" onclick="window.print()">Print the pack</button>
    <form method="post" action="/<?= View::text($slug) ?>/payouts/close">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <button class="btn btn-primary" type="submit">Close <?= View::text($monthName) ?></button>
    </form>
    <form method="post" action="/<?= View::text($slug) ?>/payouts/checks">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <input type="hidden" name="run_id" value="<?= View::text($runId) ?>">
        <label for="starting_check">First check number</label>
        <input class="input" id="starting_check" name="starting_check" inputmode="numeric">
        <button class="btn" type="submit">Write checks</button>
    </form>
</div>
<style>
.vendor-sheet { display: none; }
@media print {
    .vendor-sheet { display: block; break-before: page; }
    .no-print { display: none; }
}
</style>
