<?php

declare(strict_types=1);

use PerfectConsign\Ledger\Money;
use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $label */
/** @var string $month */
/** @var bool $print */
/** @var string|null $error */
/** @var array{gross: int, exempt: int, returns: int, taxable: int, collected: int}|null $filing */
/** @var list<array{label: string, base_minor: int}> $rates */
/** @var array{opening: int, collected: int, paid: int, closing: int}|null $liability */
/** @var list<string> $headers */
/** @var list<list<string>> $lines */
/** @var string $download */

$base = '/' . $slug . '/reports/sales-tax';
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Monthly sales tax</h1>
        <?php if ($label !== ''): ?>
            <p class="muted"><?= View::text($label) ?></p>
        <?php endif; ?>
    </div>
    <?php if (!$print): ?>
        <a class="btn" href="<?= View::text('/' . $slug . '/reports/' . $download) ?>">Download CSV</a>
        <?php if ($error === null): ?>
            <a href="<?= View::text($base) ?>?month=<?= View::text($month) ?>&amp;print=1">Print</a>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php if (!$print): ?>
    <form method="get" action="<?= View::text($base) ?>">
        <label for="month">Month</label>
        <input id="month" name="month" type="month" value="<?= View::text(preg_match('/^\d{4}-\d{2}$/', $month) === 1 ? $month : '') ?>">
        <button type="submit">Show</button>
    </form>
<?php endif; ?>
<?php if ($error !== null && $error !== ''): ?>
    <p class="error"><?= View::text($error) ?></p>
<?php elseif ($filing !== null && $liability !== null): ?>
    <h2>Filing</h2>
    <table>
        <tbody>
            <tr><th>Gross</th><td><?= View::text(View::money($filing['gross'])) ?></td></tr>
            <tr><th>Exempt</th><td><?= View::text(View::money($filing['exempt'])) ?></td></tr>
            <tr><th>Returns</th><td><?= View::text(View::money($filing['returns'])) ?></td></tr>
            <tr><th>Taxable sales</th><td><?= View::text(View::money($filing['taxable'])) ?></td></tr>
            <tr><th>Tax collected</th><td><?= View::text(View::money($filing['collected'])) ?></td></tr>
            <?php foreach ($rates as $rate): ?>
                <tr><td><?= View::text($rate['label'] . ' on ' . View::money($rate['base_minor'])) ?></td></tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <h2>What you still owe</h2>
    <table>
        <tbody>
            <tr><th>Started the month</th><td><?= View::text(View::money($liability['opening'])) ?></td></tr>
            <tr><th>Collected</th><td><?= View::text(View::money($liability['collected'])) ?></td></tr>
            <tr><th>Paid to the state</th><td><?= View::text(View::money($liability['paid'])) ?></td></tr>
            <tr><th>Still owe</th><td><?= View::text(View::money($liability['closing'])) ?></td></tr>
        </tbody>
    </table>
    <?php if ($liability['closing'] > 0): ?>
        <p><a href="/<?= View::text($slug) ?>/tax?amount=<?= View::text(Money::decimal($liability['closing'])) ?>">Pay the sales tax still owed</a></p>
    <?php else: ?>
        <p>Nothing is owed.</p>
    <?php endif; ?>
    <?php if ($lines !== []): ?>
        <h2>Detail</h2>
        <div class="card table-wrap">
            <table>
                <thead>
                    <tr>
                        <?php foreach ($headers as $header): ?>
                            <th><?= View::text($header) ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lines as $line): ?>
                        <tr>
                            <?php foreach ($line as $cell): ?>
                                <td><?= View::text($cell) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php endif; ?>
