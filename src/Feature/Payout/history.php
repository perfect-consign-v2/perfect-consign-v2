<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Scalar\RowValue;
use PerfectConsign\Shared\Web\ListTable;

/** @var string $slug */
/** @var string $csrf */
/** @var list<array<string, mixed>> $runs */
/** @var list<array{code: string, name: string, mode: string}> $excluded */
/** @var string|null $error */
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Past payouts</h1>
    </div>
    <a class="btn" href="/<?= View::text($slug) ?>/payouts">Pay vendors</a>
</div>
<div class="list-dt-wrap">
<?php
ListTable::open(['id' => 'payout-history', 'export' => 'payout-history']);
ListTable::head([
    ['label' => 'Run', 'sort' => 'num'],
    ['label' => 'Period'],
    ['label' => 'Rent month'],
    ['label' => 'Tender'],
    ['label' => 'Status'],
    ['label' => '', 'actions' => true],
]);
?>
    <tbody>
    <?php foreach ($runs as $run): ?>
        <tr>
            <td><?= View::text((string) RowValue::integer($run['run_no'] ?? null)) ?></td>
            <td><?= View::date(RowValue::string($run['sales_from'] ?? null)) ?> – <?= View::date(RowValue::string($run['sales_to'] ?? null)) ?></td>
            <td><?php $rentMonth = $run['rent_month'] ?? null; ?><?= is_string($rentMonth) && $rentMonth !== '' ? View::date($rentMonth) : '—' ?></td>
            <td><?php
                $tender = RowValue::string($run['tender'] ?? null);
                $tenderWords = ['check' => 'Check', 'drawer_cash' => 'Cash from the drawer', 'safe_cash' => 'Cash from the safe'];
                echo View::text($tenderWords[$tender] ?? $tender);
            ?></td>
            <td>
                <?php
                $status = RowValue::string($run['status'] ?? null);
                $statusWords = ['paid' => 'Paid', 'settled' => 'Paid', 'draft' => 'Open', 'reversed' => 'Undone'];
                echo View::text($statusWords[$status] ?? $status);
                ?>
                <?php if (is_string($run['undone_by'] ?? null) && $run['undone_by'] !== '' && is_string($run['undone_reason'] ?? null) && $run['undone_reason'] !== '' && is_string($run['undone_at'] ?? null) && $run['undone_at'] !== ''): ?>
                    <div class="muted">Undone by <?= View::text($run['undone_by']) ?> on <?= View::date($run['undone_at']) ?> — <?= View::text($run['undone_reason']) ?></div>
                <?php endif; ?>
            </td>
            <td>
                <?php if (in_array($run['status'] ?? '', ['settled', 'paid'], true) && ($run['reverses_run_id'] ?? '') === ''): ?>
                    <a href="/<?= View::text($slug) ?>/payouts/report?run=<?= View::text(RowValue::string($run['id'] ?? null)) ?>">Print each vendor's sales</a>
                    <?php if (($run['tender'] ?? '') === 'check' && ($run['status'] ?? '') === 'paid'): ?>
                        <a href="/<?= View::text($slug) ?>/payouts/checks?run=<?= View::text(RowValue::string($run['id'] ?? null)) ?>">Print checks</a>
                    <?php endif; ?>
                    <form method="post" action="/<?= View::text($slug) ?>/payouts/undo">
                        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                        <input type="hidden" name="run_id" value="<?= View::text(RowValue::string($run['id'] ?? null)) ?>">
                        <input name="reason" required placeholder="Reason" aria-label="Reason">
                        <button type="submit">Undo this payout</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php ListTable::assets(); ?>
<?php if (is_string($error) && $error !== ''): ?>
    <p class="error"><?= View::text($error) ?></p>
<?php endif; ?>
<?php if ($excluded !== []): ?>
    <p>Vendors excluded: <?php foreach ($excluded as $vendor): ?><?= View::text($vendor['name']) ?> <?php endforeach; ?></p>
<?php endif; ?>
