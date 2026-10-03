<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var string $displayName */
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
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Pay vendors</h1>
        <p class="muted"><?= View::text($displayName) ?></p>
    </div>
    <a class="btn btn-ghost" href="/<?= View::text($slug) ?>/payouts/past">Past payouts</a>
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
                <tr class="envelope">
                    <td><?= View::text($row['name']) ?> <span class="muted"><?= View::text($row['code']) ?></span></td>
                    <td><?= View::text($row['booth'] === '' ? '—' : $row['booth']) ?></td>
                    <td><?= View::text(View::money($row['payable'])) ?></td>
                    <td><?= View::text(View::money($row['prior'])) ?></td>
                    <td><?= View::text(View::money($row['charged'])) ?></td>
                    <td><?= View::text(View::money($row['paid'])) ?></td>
                    <td><?= $row['check'] > 0 ? View::text(View::money($row['check'])) : '' ?></td>
                    <td><?php if ($row['owes'] > 0): ?><span class="text-danger">Amount owed to store: <?= View::text(View::money($row['owes'])) ?></span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="muted"><?= View::text((string) count($pack)) ?> envelopes. <?= View::text((string) $checks) ?> checks, <?= View::text(View::money($checkTotal)) ?>. <?= View::text((string) $owesCount) ?> owe the store, <?= View::text(View::money($owesTotal)) ?>.</p>
    </div>
</div>
<?php if ($excluded !== []): ?>
    <p class="muted">Vendors excluded: <?php foreach ($excluded as $vendor): ?><?= View::text($vendor['code']) ?> (<?= View::text($vendor['mode']) ?>) <?php endforeach; ?></p>
<?php endif; ?>
<div class="stack mt-6">
    <button class="btn" type="button" onclick="window.print()">Print the pack</button>
    <form method="post" action="/<?= View::text($slug) ?>/payouts/close">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <button class="btn" type="submit">Close</button>
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
@media print {
    .envelope { break-after: page; }
    .btn, form, .page-head a { display: none; }
}
</style>
