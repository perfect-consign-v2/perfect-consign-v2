<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $today */
/** @var list<array{vendor_id: string, vendor: string, code: string, moved_on: string, moved: int, paid: int, remitted: int, held: int, days: int, references: string}> $rows */

$held = 0;
foreach ($rows as $row) {
    $held += $row['held'];
}
?>
<div class="page-head">
    <div class="titles">
        <nav class="breadcrumb"><a href="/<?= View::text($slug) ?>/reports">Reports</a><span class="sep">/</span><span>Unclaimed money</span></nav>
        <h1 class="h1">Unclaimed money</h1>
        <p class="muted">As of <?= View::date($today) ?></p>
    </div>
    <div class="row gap-2 no-print">
        <button class="btn" type="button" data-print>Print</button>
        <a class="btn" href="/<?= View::text($slug) ?>/reports/unclaimed.csv">Download CSV</a>
    </div>
</div>
<p class="no-print">Money the store owed vendors it could not reach: what is still held for each, how long, what was paid to them, and what was sent to the state. Use it for the yearly unclaimed property report.</p>

<?php if ($rows === []): ?>
    <p class="mt-4">No money has been moved to unclaimed money.</p>
<?php else: ?>
    <div class="card mt-6">
        <div class="card-body">
            <table class="table">
                <thead><tr><th>Vendor</th><th>Moved on</th><th class="num">Days held</th><th class="num">Moved</th><th class="num">Paid to vendor</th><th class="num">Sent to the state</th><th class="num">Still held</th><th>State report numbers</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><a href="/<?= View::text($slug) ?>/vendors/<?= View::text($row['vendor_id']) ?>"><?= View::text($row['vendor']) ?></a></td>
                        <td><?= View::text(View::date($row['moved_on'])) ?></td>
                        <td class="num"><?= View::text((string) $row['days']) ?></td>
                        <td class="num"><?= View::text(View::money($row['moved'])) ?></td>
                        <td class="num"><?= View::text(View::money($row['paid'])) ?></td>
                        <td class="num"><?= View::text(View::money($row['remitted'])) ?></td>
                        <td class="num"><?= View::text(View::money($row['held'])) ?></td>
                        <td><?= View::text($row['references']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><th colspan="6">Still held in all</th><th class="num"><?= View::text(View::money($held)) ?></th><th></th></tr></tfoot>
            </table>
        </div>
    </div>
<?php endif; ?>
