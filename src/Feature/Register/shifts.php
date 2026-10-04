<?php

declare(strict_types=1);

use PerfectConsign\Feature\Register\DenominationCount;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Scalar\RowValue;
use PerfectConsign\Shared\Web\ListTable;

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
<div class="card table-wrap list-dt-wrap mt-6">
    <?php if ($shifts === []): ?>
        <div class="empty"><p>No shifts yet.</p></div>
    <?php else: ?>
        <?php
        ListTable::open(['id' => 'shifts', 'export' => 'shifts']);
        ListTable::head([
            ['label' => 'Cashier'],
            ['label' => 'Opened'],
            ['label' => 'Closed'],
            ['label' => 'Opening float', 'sort' => 'num'],
            ['label' => 'Counted cash', 'sort' => 'num'],
            ['label' => 'Cash'],
            ['label' => 'Card'],
            ['label' => 'Checks'],
            ['label' => '', 'actions' => true],
        ]);
        ?>
            <tbody>
            <?php foreach ($shifts as $shift): ?>
                <?php
                $id = RowValue::string($shift['id'] ?? null);
                $closed = is_string($shift['closed_at'] ?? null) && $shift['closed_at'] !== '';
                ?>
                <?php
                $cashDiff = $closed && is_string($shift['cash_difference'] ?? null) ? $shift['cash_difference'] : '';
                $cashMark = $closed && is_string($shift['cash_mark'] ?? null) ? $shift['cash_mark'] : '';
                $cardCount = $closed
                    ? 'Counted ' . DenominationCount::dollars(RowValue::integer($shift['counted_card_minor'] ?? 0))
                        . ', posted ' . DenominationCount::dollars(RowValue::integer($shift['posted_card_minor'] ?? 0))
                    : '';
                $checksCount = $closed
                    ? 'Counted ' . DenominationCount::dollars(RowValue::integer($shift['counted_checks_minor'] ?? 0))
                        . ', posted ' . DenominationCount::dollars(RowValue::integer($shift['posted_checks_minor'] ?? 0))
                    : '';
                ?>
                <tr>
                    <td><?= View::text(RowValue::string($shift['cashier'] ?? null)) ?></td>
                    <td><?= View::text(is_string($shift['opened_at'] ?? null) ? $shift['opened_at'] : '') ?></td>
                    <td><?= $closed ? View::text($shift['closed_at']) : 'Open' ?></td>
                    <td><?= View::text(DenominationCount::dollars(RowValue::integer($shift['opening_cash_minor'] ?? 0))) ?></td>
                    <td><?= $closed ? View::text(DenominationCount::dollars(RowValue::integer($shift['counted_cash_minor'] ?? 0))) : '' ?></td>
                    <td class="<?= View::text($cashMark) ?>"><?= View::text($cashDiff) ?></td>
                    <td><?= View::text($cardCount) ?></td>
                    <td><?= View::text($checksCount) ?></td>
                    <td><?php if ($closed): ?><a href="/<?= View::text($slug) ?>/registers/sessions/<?= View::text($id) ?>">Z-tape</a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php ListTable::assets(); ?>
