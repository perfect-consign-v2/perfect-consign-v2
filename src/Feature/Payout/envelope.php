<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $displayName */
/** @var list<array{vendor_id: string, code: string, name: string, booth: string, sold_on: string, item_code: string, description: string, price: int, commission: int, tax: int, check: int, owes: int, check_number: string}> $rows */

$css = file_get_contents(__DIR__ . '/envelope.css');
if ($css === false) {
    throw new RuntimeException('the envelope stylesheet is missing');
}
$vendors = [];
foreach ($rows as $row) {
    $vendors[$row['vendor_id']][] = $row;
}
?>
<style><?= $css ?></style>
<div class="no-print">
    <button class="btn btn-primary" type="button" data-print>Print</button>
</div>
<?php foreach ($vendors as $lines): ?>
    <?php $head = $lines[0]; ?>
    <section class="envelope">
        <h1><?= View::text($displayName) ?></h1>
        <h2><?= View::text($head['name']) ?> <span><?= View::text($head['code']) ?></span></h2>
        <table>
            <thead>
                <tr><th>Item</th><th>Date</th><th>Price</th><th>Store fee</th><th>Tax</th></tr>
            </thead>
            <tbody>
            <?php foreach ($lines as $line): ?>
                <?php if ($line['item_code'] === ''): ?>
                    <?php continue; ?>
                <?php endif; ?>
                <tr>
                    <td><?= View::text($line['item_code']) ?> <?= View::text($line['description']) ?></td>
                    <td><?= View::text($line['sold_on']) ?></td>
                    <td><?= View::text(View::money($line['price'])) ?></td>
                    <td><?= View::text(View::money($line['commission'])) ?></td>
                    <td><?= View::text(View::money($line['tax'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($head['check'] > 0): ?>
            <p>Check: <?= View::text(View::money($head['check'])) ?></p>
            <?php if ($head['check_number'] !== ''): ?>
                <p>Check number <?= View::text($head['check_number']) ?></p>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($head['owes'] > 0): ?>
            <p class="owed">Amount owed to store: <?= View::text(View::money($head['owes'])) ?></p>
        <?php endif; ?>
        <p>Report / reprinted from the stored record.</p>
    </section>
<?php endforeach; ?>
<script>
document.querySelectorAll('[data-print]').forEach(function (button) {
    button.addEventListener('click', function () {
        window.print();
    });
});
</script>
