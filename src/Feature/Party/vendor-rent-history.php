<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $sellerBase */
/** @var array{id: string} $vendor */
/** @var array{months: list<array{month: string, charges: list<array{booth: string, kind: string, what: string, date: string, journal_id: string, charged: int, waived: bool, paid: int, owed: int, payments: list<array{number: string, date: string, amount: int, how: string, register: string, cashier: string, run_id: string, run_date: string, journal_id: string, paid_by: string}>}>}>, more: bool} $rentPaid */
/** @var bool $rentPaidAll */

?>
<section class="card mt-6" id="vendor-rent-history">
    <div class="card-head"><div class="h3">Rent history</div></div>
    <div class="card-body stack">
        <?php if ($rentPaid['months'] === []): ?>
            <p class="muted">No rent has been charged yet.</p>
        <?php endif; ?>
        <?php foreach ($rentPaid['months'] as $month): ?>
            <div class="rent-history-month">
                <div class="h4"><?= View::text(date('F Y', (int) strtotime($month['month']))) ?></div>
                <ul class="rent-history-lines">
                    <?php foreach ($month['charges'] as $charge): ?>
                        <li>
                            <strong>Booth <?= View::text($charge['booth']) ?></strong>
                            · <?= View::text($charge['what']) ?>
                            · <?= View::text(View::money($charge['charged'])) ?> charged
                            <?php if ($charge['waived']): ?>
                                · <span class="badge badge-success">Waived</span>
                            <?php endif; ?>
                            <?php foreach ($charge['payments'] as $paid): ?>
                                <?php
                                $where = $paid['run_date'] !== ''
                                    ? 'payout of ' . View::date($paid['run_date'])
                                    : 'receipt #' . $paid['number'] . ($paid['register'] !== '' ? ', ' . $paid['register'] : '');
                                ?>
                                · <?= View::text(View::money($paid['amount']) . ' ' . strtolower($paid['how']) . ' (' . $where . ')') ?>
                            <?php endforeach; ?>
                            <?php if (!$charge['waived']): ?>
                                · <span class="<?= $charge['owed'] > 0 ? 'strong' : 'muted' ?>"><?= View::text(View::money($charge['owed'])) ?> owed</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
        <?php if ($rentPaid['more']): ?>
            <p><a href="<?= View::text($sellerBase) ?>/<?= View::text($vendor['id']) ?>?history=all#vendor-rent-history">Show all</a></p>
        <?php elseif ($rentPaidAll): ?>
            <p><a href="<?= View::text($sellerBase) ?>/<?= View::text($vendor['id']) ?>#vendor-rent-history">Show recent months</a></p>
        <?php endif; ?>
    </div>
</section>
