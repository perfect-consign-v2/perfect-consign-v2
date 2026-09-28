<?php

declare(strict_types=1);

use PerfectConsign\Feature\Register\DenominationCount;
use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var array<string, mixed> $tape */

$name = is_string($tape['name'] ?? null) ? $tape['name'] : '';
$registerId = is_string($tape['register_id'] ?? null) ? $tape['register_id'] : '';
$opening = is_array($tape['opening'] ?? null) ? $tape['opening'] : [];
$closing = is_array($tape['closing'] ?? null) ? $tape['closing'] : [];
$lines = is_array($tape['lines'] ?? null) ? $tape['lines'] : [];
$drops = is_array($tape['drops'] ?? null) ? $tape['drops'] : [];
$tenders = is_array($tape['tenders'] ?? null) ? $tape['tenders'] : [];
$closed = ($tape['closed'] ?? false) === true;
$cardPosted = is_int($tenders['card'] ?? null) ? $tenders['card'] : 0;
$checksPosted = is_int($tenders['checks'] ?? null) ? $tenders['checks'] : 0;
$stored = [
    'gift_certificate' => 'Gift certificates',
    'gift_card' => 'Gift cards',
    'store_credit' => 'Store credit',
];
?>
<div class="page-head no-print">
    <div class="titles">
        <h1 class="h1">Z-tape · <?= View::text($name) ?></h1>
    </div>
    <button class="btn btn-primary" type="button" onclick="window.print()">Print</button>
    <a class="btn" href="/<?= View::text($slug) ?>/registers/<?= View::text($registerId) ?>/shifts">Shifts</a>
</div>
<article class="card z-tape">
    <div class="card-body stack">
        <h1 class="h1 print-only"><?= View::text($name) ?> Z-tape</h1>
        <p>Cashier <?= View::text(is_string($tape['cashier'] ?? null) ? $tape['cashier'] : '') ?></p>
        <p>Opened <?= View::text(is_string($tape['opened_at'] ?? null) ? $tape['opened_at'] : '') ?></p>
        <?php if ($closed): ?>
            <p>Closed <?= View::text(is_string($tape['closed_at'] ?? null) ? $tape['closed_at'] : '') ?></p>
        <?php else: ?>
            <p>This shift is still open.</p>
        <?php endif; ?>
        <h2 class="h3">Opening count</h2>
        <ul>
            <?php foreach ($opening as $row): ?>
                <?php if (!is_array($row)) { continue; } ?>
                <li><?= View::text(is_string($row['label'] ?? null) ? $row['label'] : '') ?> × <?= View::text((string) ($row['count'] ?? 0)) ?></li>
            <?php endforeach; ?>
        </ul>
        <p>Opening float <?= View::text(is_string($tape['opening_cash'] ?? null) ? $tape['opening_cash'] : '') ?></p>
        <?php if ($closed): ?>
            <h2 class="h3">Closing count</h2>
            <ul>
                <?php foreach ($closing as $row): ?>
                    <?php if (!is_array($row)) { continue; } ?>
                    <li><?= View::text(is_string($row['label'] ?? null) ? $row['label'] : '') ?> × <?= View::text((string) ($row['count'] ?? 0)) ?></li>
                <?php endforeach; ?>
            </ul>
            <p>Counted cash <?= View::text(is_string($tape['counted_cash'] ?? null) ? $tape['counted_cash'] : '') ?></p>
        <?php endif; ?>
        <h2 class="h3">Expected cash</h2>
        <ul>
            <?php foreach ($lines as $line): ?>
                <?php if (!is_array($line)) { continue; } ?>
                <li><?= View::text(is_string($line['label'] ?? null) ? $line['label'] : '') ?> <?= View::text(is_string($line['amount'] ?? null) ? $line['amount'] : '') ?></li>
            <?php endforeach; ?>
        </ul>
        <p>Expected drawer <?= View::text(is_string($tape['expected'] ?? null) ? $tape['expected'] : '') ?></p>
        <h2 class="h3">Cash drops</h2>
        <?php if ($drops === []): ?>
            <p>None</p>
        <?php else: ?>
            <ul>
                <?php foreach ($drops as $drop): ?>
                    <?php if (!is_array($drop)) { continue; } ?>
                    <li><?= View::text(is_string($drop['when'] ?? null) ? $drop['when'] : '') ?> <?= View::text(is_string($drop['amount'] ?? null) ? $drop['amount'] : '') ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <h2 class="h3">Card and checks</h2>
        <p>Card posted <?= View::text(DenominationCount::dollars($cardPosted)) ?><?php if ($closed): ?>, counted <?= View::text(is_string($tape['counted_card'] ?? null) ? $tape['counted_card'] : '') ?><?php endif; ?></p>
        <p>Checks posted <?= View::text(DenominationCount::dollars($checksPosted)) ?><?php if ($closed): ?>, counted <?= View::text(is_string($tape['counted_checks'] ?? null) ? $tape['counted_checks'] : '') ?><?php endif; ?></p>
        <h2 class="h3">Stored value</h2>
        <?php foreach ($stored as $role => $label): ?>
            <?php $net = is_int($tenders[$role] ?? null) ? $tenders[$role] : 0; ?>
            <p><?= View::text($label) ?> <?= View::text(DenominationCount::dollars($net)) ?></p>
        <?php endforeach; ?>
    </div>
</article>
