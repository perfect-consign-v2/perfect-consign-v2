<?php

declare(strict_types=1);

use PerfectConsign\Feature\Register\DenominationCount;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Scalar\RowValue;

/** @var string $slug */
/** @var string $registerId */
/** @var string $name */
/** @var list<array<string, mixed>> $shifts */
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1"><?= View::text($name) ?> shifts</h1>
    </div>
    <a class="btn" href="/<?= View::text($slug) ?>/registers">Registers</a>
</div>
<div class="card table-wrap mt-6">
    <?php if ($shifts === []): ?>
        <div class="empty"><p>No shifts yet.</p></div>
    <?php else: ?>
        <table class="table">
            <thead>
                <tr>
                    <th>Cashier</th>
                    <th>Opened</th>
                    <th>Closed</th>
                    <th>Opening float</th>
                    <th>Counted cash</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($shifts as $shift): ?>
                <?php
                $id = RowValue::string($shift['id'] ?? null);
                $closed = is_string($shift['closed_at'] ?? null) && $shift['closed_at'] !== '';
                ?>
                <tr>
                    <td><?= View::text(RowValue::string($shift['cashier'] ?? null)) ?></td>
                    <td><?= View::text(is_string($shift['opened_at'] ?? null) ? $shift['opened_at'] : '') ?></td>
                    <td><?= $closed ? View::text($shift['closed_at']) : 'Open' ?></td>
                    <td><?= View::text(DenominationCount::dollars(RowValue::integer($shift['opening_cash_minor'] ?? 0))) ?></td>
                    <td><?= $closed ? View::text(DenominationCount::dollars(RowValue::integer($shift['counted_cash_minor'] ?? 0))) : '' ?></td>
                    <td><?php if ($closed): ?><a href="/<?= View::text($slug) ?>/registers/sessions/<?= View::text($id) ?>">Z-tape</a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
