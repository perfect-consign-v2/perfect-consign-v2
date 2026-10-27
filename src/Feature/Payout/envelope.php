<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $displayName */
/** @var string $slug */
/** @var list<array{vendor_id: string, code: string, name: string, booth: string, sold_on: string, item_code: string, description: string, price: int, quantity: int, commission: int, rent: int, check: int, owes: int, check_number: string}> $rows */

$mallSlug = isset($slug) && is_string($slug) ? $slug : '';
$vendors = [];
$storedReport = false;
foreach ($rows as $row) {
    $vendors[$row['vendor_id']][] = $row;
    if ($row['check_number'] !== '') {
        $storedReport = true;
    }
}
uasort($vendors, static function (array $left, array $right): int {
    $leftCode = '';
    $rightCode = '';
    if (isset($left[0]) && is_array($left[0]) && is_string($left[0]['code'] ?? null)) {
        $leftCode = $left[0]['code'];
    }
    if (isset($right[0]) && is_array($right[0]) && is_string($right[0]['code'] ?? null)) {
        $rightCode = $right[0]['code'];
    }

    return strnatcasecmp($leftCode, $rightCode);
});
?>
<link rel="stylesheet" href="/assets/envelope.css">
<div class="no-print">
    <p>One page for each vendor, with what they sold and the check.</p>
    <button class="btn btn-primary" type="button" data-print>Print</button>
    <?php if ($mallSlug !== ''): ?>
        <a class="btn" href="/<?= View::text($mallSlug) ?>/payouts">Back</a>
    <?php endif; ?>
</div>
<?php if ($vendors === []): ?>
    <p>This payout has no vendor sheets.</p>
<?php endif; ?>
<?php foreach ($vendors as $lines): ?>
    <?php $head = $lines[0]; ?>
    <section class="envelope">
        <h1><?= View::text($displayName) ?></h1>
        <h2>(<?= View::text($head['code']) ?>) <?= View::text($head['name']) ?> <span><?= View::text($head['code']) ?></span></h2>
        <?php if ($head['booth'] !== ''): ?>
            <p>Booth <?= View::text($head['booth']) ?></p>
        <?php endif; ?>
        <table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Date</th>
                    <th class="num">Qty</th>
                    <th class="num">Price</th>
                    <th class="num">Store fee</th>
                    <th class="num">Credits/Debits</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($lines as $line): ?>
                <?php if ($line['item_code'] === ''): ?>
                    <?php continue; ?>
                <?php endif; ?>
                <?php
                $quantity = $line['quantity'] > 0 ? $line['quantity'] : 1;
                $price = $line['price'] * $quantity;
                $credit = $price - $line['commission'];
                ?>
                <tr>
                    <td><?= View::text($line['item_code']) ?> <?= View::text($line['description']) ?></td>
                    <td><?= View::date($line['sold_on']) ?></td>
                    <td class="num"><?= View::text((string) $quantity) ?></td>
                    <td class="num"><?= View::text(View::money($price)) ?></td>
                    <td class="num"><?= View::text(View::money($line['commission'])) ?></td>
                    <td class="num<?= $credit < 0 ? ' debit' : '' ?>"><?php if ($credit < 0): ?>(<?= View::text(View::money(abs($credit))) ?>)<?php else: ?><?= View::text(View::money($credit)) ?><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($head['rent'] > 0): ?>
                <tr>
                    <td>Rent</td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td class="num debit">(<?= View::text(View::money($head['rent'])) ?>)</td>
                </tr>
            <?php endif; ?>
            </tbody>
            <?php if ($head['check'] > 0): ?>
                <tfoot>
                    <tr>
                        <td colspan="5"></td>
                        <td class="num">Check: <?= View::text(View::money($head['check'])) ?></td>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
        <?php if ($head['check'] > 0 && $head['check_number'] !== ''): ?>
            <p>Check number <?= View::text($head['check_number']) ?></p>
        <?php endif; ?>
        <?php if ($head['owes'] > 0): ?>
            <p class="owed">Amount owed to store: <?= View::text(View::money($head['owes'])) ?></p>
        <?php endif; ?>
        <p><?= $storedReport ? 'Report / reprinted from the stored record.' : 'Report for this payout.' ?></p>
    </section>
<?php endforeach; ?>
<script src="/assets/js/print-trigger.js"></script>
