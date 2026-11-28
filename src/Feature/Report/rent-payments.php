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
/** @var list<array{date: string, journal_id: string, number: string, vendor_id: string, vendor: string, code: string, booth: string, months: string, paid_by: string, how: string, register: string, cashier: string, run_id: string, run_date: string, voided: bool, amount: int}> $rows */
/** @var list<array{kind: string, label: string, count: int, amount: int}> $totals */
/** @var list<array{id: string, label: string}> $vendors */
/** @var list<array{id: string, label: string}> $booths */

$base = '/' . $slug . '/reports/rent-payments';
$ways = [
    'cash' => 'Cash',
    'card' => 'Card',
    'check' => 'Check',
    'split' => 'More than one way',
    'sales' => 'From sales at payout',
    'paid_ahead' => 'Paid ahead with a reservation',
    'waived' => 'Late fee waived',
    'written_off' => 'Written off as a bad debt',
];
$overall = null;
$byWay = [];
$byRegister = [];
$voided = null;
foreach ($totals as $total) {
    if ($total['kind'] === 'all') {
        $overall = $total;
    } elseif ($total['kind'] === 'paid_by') {
        $byWay[] = $total;
    } elseif ($total['kind'] === 'register') {
        $byRegister[] = $total;
    } elseif ($total['kind'] === 'voided') {
        $voided = $total;
    }
}
?>
<div class="page-head">
    <div class="titles">
        <nav class="breadcrumb"><a href="/<?= View::text($slug) ?>/reports">Reports</a><span class="sep">/</span><span>Rent payments</span></nav>
        <h1 class="h1">Rent payments</h1>
        <p class="muted"><?= View::text($label) ?></p>
    </div>
    <?php if ($error === null): ?>
        <div class="row gap-2 no-print">
            <button class="btn" type="button" data-print>Print</button>
            <a class="btn" href="<?= View::text($base) ?>.csv?<?= View::text($download) ?>">Download CSV</a>
        </div>
    <?php endif; ?>
</div>
<p class="no-print">Every way rent was paid: at the desk, from sales at payout, and late fees waived.</p>

<form class="card no-print" method="get" action="<?= View::text($base) ?>">
    <div class="card-body stack">
        <div class="report-span">
            <div>
                <label for="payments-from">From</label>
                <input class="input" id="payments-from" type="date" name="from" value="<?= View::text($from) ?>">
            </div>
            <div>
                <label for="payments-to">To</label>
                <input class="input" id="payments-to" type="date" name="to" value="<?= View::text($to) ?>">
            </div>
        </div>
        <div class="field">
            <label for="payments-vendor">Vendor</label>
            <select class="input" id="payments-vendor" name="vendor">
                <option value="">Every vendor</option>
                <?php foreach ($vendors as $choice): ?>
                    <option value="<?= View::text($choice['id']) ?>"<?= $choice['id'] === $chosen['vendor'] ? ' selected' : '' ?>><?= View::text($choice['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="payments-booth">Booth</label>
            <select class="input" id="payments-booth" name="booth">
                <option value="">Every booth</option>
                <?php foreach ($booths as $choice): ?>
                    <option value="<?= View::text($choice['id']) ?>"<?= $choice['id'] === $chosen['booth'] ? ' selected' : '' ?>><?= View::text($choice['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="payments-way">How paid</label>
            <select class="input" id="payments-way" name="paid_by">
                <option value="">Every way</option>
                <?php foreach ($ways as $key => $name): ?>
                    <option value="<?= View::text($key) ?>"<?= $key === $chosen['paid_by'] ? ' selected' : '' ?>><?= View::text($name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="payments-search">Search</label>
            <input class="input" id="payments-search" type="search" name="q" maxlength="80" value="<?= View::text($chosen['q']) ?>" placeholder="Name, vendor code, receipt number, or booth">
        </div>
        <p>
            <button class="btn btn-primary" type="submit">Show</button>
            <a class="btn" href="<?= View::text($base) ?>?<?= View::text($thisMonth) ?>">This month</a>
            <a class="btn" href="<?= View::text($base) ?>?<?= View::text($lastMonth) ?>">Last month</a>
        </p>
    </div>
</form>

<?php if ($error !== null && $error !== ''): ?>
    <p class="alert alert-danger mt-6" role="alert"><?= View::text($error) ?></p>
<?php elseif ($rows === []): ?>
    <div class="card mt-6"><div class="empty"><p>No rent was paid in this range.</p></div></div>
<?php else: ?>
    <div class="card table-wrap mt-6">
        <table class="table" id="rent-payments">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Receipt</th>
                    <th>Vendor</th>
                    <th>Booth</th>
                    <th>Months</th>
                    <th>How paid</th>
                    <th>Register</th>
                    <th>Cashier</th>
                    <th class="num">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $href = match ($row['paid_by']) {
                        'cash', 'card', 'check', 'split' => '/' . $slug . '/rent/receipt/' . $row['journal_id'],
                        'sales' => $row['run_id'] !== '' ? '/' . $slug . '/payouts/report?run=' . $row['run_id'] : '/' . $slug . '/journals/' . $row['journal_id'],
                        default => '/' . $slug . '/journals/' . $row['journal_id'],
                    };
                    ?>
                    <tr<?= $row['voided'] ? ' class="muted"' : '' ?>>
                        <td><?= View::text(View::date($row['date'])) ?></td>
                        <td><a href="<?= View::text($href) ?>">#<?= View::text($row['number']) ?></a></td>
                        <td><a href="/<?= View::text($slug) ?>/vendors/<?= View::text($row['vendor_id']) ?>"><?= View::text($row['vendor']) ?></a> <span class="muted"><?= View::text($row['code']) ?></span></td>
                        <td><?= View::text($row['booth']) ?></td>
                        <td><?= View::text($row['months']) ?></td>
                        <td>
                            <?= View::text($row['how']) ?>
                            <?php if ($row['voided']): ?>
                                <span class="badge badge-danger">Voided</span>
                            <?php endif; ?>
                        </td>
                        <td><?= View::text($row['register'] !== '' ? $row['register'] : ($row['run_date'] !== '' ? 'Payout of ' . View::date($row['run_date']) : '')) ?></td>
                        <td><?= View::text($row['cashier']) ?></td>
                        <td class="num"><?= View::text(View::money($row['amount'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <?php if ($overall !== null): ?>
                <tfoot>
                    <tr>
                        <th colspan="8"><?= View::text((string) $overall['count']) ?> <?= $overall['count'] === 1 ? 'payment' : 'payments' ?>, not counting voided</th>
                        <th class="num"><?= View::text(View::money($overall['amount'])) ?></th>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
    <div class="card mt-6">
        <div class="card-body stack">
            <p id="rent-payments-ways">
                <?php foreach ($byWay as $index => $part): ?>
                    <?= $index > 0 ? ' · ' : '' ?><?= View::text($part['label']) ?> <?= View::text(View::money($part['amount'])) ?>
                <?php endforeach; ?>
            </p>
            <?php if ($byRegister !== []): ?>
                <p id="rent-payments-registers">
                    <?php foreach ($byRegister as $index => $part): ?>
                        <?= $index > 0 ? ' · ' : '' ?><?= View::text($part['label']) ?> <?= View::text(View::money($part['amount'])) ?>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>
            <?php if ($voided !== null): ?>
                <p class="muted"><?= View::text($voided['label']) ?>: <?= View::text((string) $voided['count']) ?>, <?= View::text(View::money($voided['amount'])) ?></p>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
<script src="/assets/js/print-trigger.js"></script>
