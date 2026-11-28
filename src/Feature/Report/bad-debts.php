<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $label */
/** @var string $from */
/** @var string $to */
/** @var string|null $error */
/** @var string $thisMonth */
/** @var string $lastMonth */
/** @var string $download */
/** @var list<array{journal_id: string, date: string, vendor_id: string, vendor: string, booth: string, kind: string, amount: int, recovered: int, reason: string, who: string}> $rows */

$base = '/' . $slug . '/reports/bad-debts';
$total = 0;
$back = 0;
foreach ($rows as $row) {
    $total += $row['amount'];
    $back += $row['recovered'];
}
?>
<div class="page-head">
    <div class="titles">
        <nav class="breadcrumb"><a href="/<?= View::text($slug) ?>/reports">Reports</a><span class="sep">/</span><span>Bad debts</span></nav>
        <h1 class="h1">Bad debts</h1>
        <p class="muted"><?= View::text($label) ?></p>
    </div>
    <?php if ($error === null): ?>
        <div class="row gap-2 no-print">
            <button class="btn" type="button" data-print>Print</button>
            <a class="btn" href="<?= View::text($base) ?>.csv?<?= View::text($download) ?>">Download CSV</a>
        </div>
    <?php endif; ?>
</div>
<p class="no-print">Rent and returns written off as a loss, who did it and why, and what was taken back since.</p>

<form class="card no-print" method="get" action="<?= View::text($base) ?>">
    <div class="card-body stack">
        <div class="report-span">
            <div>
                <label for="bad-from">From</label>
                <input class="input" id="bad-from" type="date" name="from" value="<?= View::text($from) ?>">
            </div>
            <div>
                <label for="bad-to">To</label>
                <input class="input" id="bad-to" type="date" name="to" value="<?= View::text($to) ?>">
            </div>
        </div>
        <p>
            <button class="btn btn-primary" type="submit">Show</button>
            <a class="btn" href="<?= View::text($base) ?>?<?= View::text($thisMonth) ?>">This month</a>
            <a class="btn" href="<?= View::text($base) ?>?<?= View::text($lastMonth) ?>">Last month</a>
        </p>
    </div>
</form>

<?php if ($error !== null): ?>
    <div class="alert alert-danger mt-4" role="alert"><?= View::text($error) ?></div>
<?php elseif ($rows === []): ?>
    <p class="mt-4">Nothing was written off in these dates.</p>
<?php else: ?>
    <div class="card mt-6">
        <div class="card-body">
            <table class="table">
                <thead><tr><th>Date</th><th>Vendor</th><th>Booth</th><th>What</th><th class="num">Amount</th><th class="num">Taken back</th><th>Reason</th><th>Who</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><a href="/<?= View::text($slug) ?>/journals/<?= View::text($row['journal_id']) ?>"><?= View::text(View::date($row['date'])) ?></a></td>
                        <td><a href="/<?= View::text($slug) ?>/vendors/<?= View::text($row['vendor_id']) ?>"><?= View::text($row['vendor']) ?></a></td>
                        <td><?= View::text($row['booth']) ?></td>
                        <td><?= View::text($row['kind'] === 'rent' ? 'Rent' : 'Returns') ?></td>
                        <td class="num"><?= View::text(View::money($row['amount'])) ?></td>
                        <td class="num"><?= View::text(View::money($row['recovered'])) ?></td>
                        <td><?= View::text($row['reason']) ?></td>
                        <td><?= View::text($row['who']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><th colspan="4">Total</th><th class="num"><?= View::text(View::money($total)) ?></th><th class="num"><?= View::text(View::money($back)) ?></th><th colspan="2"></th></tr></tfoot>
            </table>
        </div>
    </div>
<?php endif; ?>
