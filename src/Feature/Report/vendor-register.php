<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $label */
/** @var string $from */
/** @var string $to */
/** @var string|null $error */
/** @var array{vendor: string, booth: string, paid_by: string, q: string} $chosen */
/** @var string $thisMonth */
/** @var string $lastMonth */
/** @var string $download */
/** @var list<array{kind: string, date: string, journal_id: string, number: string, what: string, booth: string, debit: ?int, credit: ?int, balance: int}> $rows */
/** @var list<array{id: string, label: string}> $vendors */
/** @var list<array{id: string, label: string}> $booths */

$base = '/' . $slug . '/reports/vendor-register';
$opening = null;
$closing = null;
$lines = [];
$split = [];
foreach ($rows as $row) {
    if ($row['kind'] === 'opening') {
        $opening = $row;
    } elseif ($row['kind'] === 'closing') {
        $closing = $row;
    } elseif ($row['kind'] === 'line') {
        $lines[] = $row;
    } else {
        $split[] = $row;
    }
}
$vendorName = '';
foreach ($vendors as $choice) {
    if ($choice['id'] === $chosen['vendor']) {
        $vendorName = $choice['label'];
    }
}
?>
<div class="page-head">
    <div class="titles">
        <nav class="breadcrumb"><a href="/<?= View::text($slug) ?>/reports">Reports</a><span class="sep">/</span><span>Vendor register</span></nav>
        <h1 class="h1">Vendor register</h1>
        <p class="muted"><?= View::text($vendorName !== '' ? $vendorName . ' · ' . $label : $label) ?></p>
    </div>
    <?php if ($chosen['vendor'] !== '' && $error === null): ?>
        <div class="row gap-2 no-print">
            <button class="btn" type="button" data-print>Print</button>
            <a class="btn" href="<?= View::text($base) ?>.csv?<?= View::text($download) ?>">Download CSV</a>
        </div>
    <?php endif; ?>
</div>
<p class="no-print">Every money event for one vendor, oldest first. A positive balance means the store owes them. A negative balance means they owe the store.</p>

<form class="card no-print" method="get" action="<?= View::text($base) ?>">
    <div class="card-body stack">
        <div class="field">
            <label for="register-vendor">Vendor</label>
            <select class="input" id="register-vendor" name="vendor" required>
                <option value="">Choose a vendor</option>
                <?php foreach ($vendors as $choice): ?>
                    <option value="<?= View::text($choice['id']) ?>"<?= $choice['id'] === $chosen['vendor'] ? ' selected' : '' ?>><?= View::text($choice['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="register-booth">Booth</label>
            <select class="input" id="register-booth" name="booth">
                <option value="">Every booth, and their sales and payouts</option>
                <?php foreach ($booths as $choice): ?>
                    <option value="<?= View::text($choice['id']) ?>"<?= $choice['id'] === $chosen['booth'] ? ' selected' : '' ?>><?= View::text($choice['label']) ?> (rent only)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="report-span">
            <div>
                <label for="register-from">From</label>
                <input class="input" id="register-from" type="date" name="from" value="<?= View::text($from) ?>">
            </div>
            <div>
                <label for="register-to">To</label>
                <input class="input" id="register-to" type="date" name="to" value="<?= View::text($to) ?>">
            </div>
        </div>
        <p>
            <button class="btn btn-primary" type="submit">Show</button>
            <?php if ($chosen['vendor'] !== ''): ?>
                <a class="btn" href="<?= View::text($base) ?>?<?= View::text($thisMonth) ?>">This month</a>
                <a class="btn" href="<?= View::text($base) ?>?<?= View::text($lastMonth) ?>">Last month</a>
            <?php endif; ?>
        </p>
    </div>
</form>

<?php if ($error !== null && $error !== ''): ?>
    <p class="alert alert-danger mt-6" role="alert"><?= View::text($error) ?></p>
<?php elseif ($chosen['vendor'] === '' || $opening === null || $closing === null): ?>
    <div class="card mt-6"><div class="empty"><p>Choose a vendor to see their register.</p></div></div>
<?php else: ?>
    <div class="card table-wrap mt-6">
        <table class="table" id="vendor-register">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>What happened</th>
                    <th>Booth</th>
                    <th class="num">Debit</th>
                    <th class="num">Credit</th>
                    <th class="num">Balance</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?= View::text(View::date($opening['date'])) ?></td>
                    <td><?= View::text($opening['what']) ?></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td class="num"><?= View::text(View::money($opening['balance'])) ?></td>
                </tr>
                <?php if ($lines === []): ?>
                    <tr><td colspan="6" class="muted">Nothing happened in this range.</td></tr>
                <?php endif; ?>
                <?php foreach ($lines as $line): ?>
                    <tr>
                        <td><?= View::text(View::date($line['date'])) ?></td>
                        <td><a href="/<?= View::text($slug) ?>/journals/<?= View::text($line['journal_id']) ?>"><?= View::text($line['what']) ?></a></td>
                        <td><?= View::text($line['booth']) ?></td>
                        <td class="num"><?= ($line['debit'] ?? 0) > 0 ? View::text(View::money((int) $line['debit'])) : '' ?></td>
                        <td class="num"><?= ($line['credit'] ?? 0) > 0 ? View::text(View::money((int) $line['credit'])) : '' ?></td>
                        <td class="num"><?= View::text(View::money($line['balance'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="3">Totals, and the closing balance on <?= View::text(View::date($closing['date'])) ?></th>
                    <th class="num"><?= View::text(View::money((int) $closing['debit'])) ?></th>
                    <th class="num"><?= View::text(View::money((int) $closing['credit'])) ?></th>
                    <th class="num"><?= View::text(View::money($closing['balance'])) ?></th>
                </tr>
            </tfoot>
        </table>
    </div>
    <div class="card mt-6">
        <div class="card-body">
            <p id="vendor-register-split">
                <?php foreach ($split as $part): ?>
                    <?= View::text($part['what']) ?> <?= View::text(View::money($part['balance'])) ?> ·
                <?php endforeach; ?>
                <strong>Net <?= View::text(View::money($closing['balance'])) ?></strong>
            </p>
        </div>
    </div>
<?php endif; ?>
<script src="/assets/js/print-trigger.js"></script>
