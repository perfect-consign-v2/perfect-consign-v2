<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var string $today */
/** @var string $sellerBase */
/** @var bool $isOwner */
/** @var array{id: string, name: string} $vendor */
/** @var list<array{kind: string, booth: string, open: int, since: string, eligible_on: string}> $debts */
/** @var list<array{journal_id: string, said: string, left: int}> $writeOffs */

$ready = $debts !== [];
foreach ($debts as $debt) {
    if ($debt['eligible_on'] > $today) {
        $ready = false;
    }
}
$record = $sellerBase . '/' . $vendor['id'];
?>
<?php if ($debts !== [] || $writeOffs !== []): ?>
<section class="card mt-6" aria-labelledby="vendor-debts">
    <div class="card-head"><div class="h3" id="vendor-debts">They owe us</div></div>
    <div class="card-body stack">
        <?php if ($debts !== []): ?>
            <ul class="stack text-sm">
                <?php foreach ($debts as $debt): ?>
                    <?php $days = (int) ((strtotime($today) - strtotime($debt['since'])) / 86400); ?>
                    <li>
                        <strong><?= View::text($debt['kind'] === 'rent' ? 'Rent for booth ' . $debt['booth'] : 'Returns after a payout') ?></strong>
                        · <?= View::text(View::money($debt['open'])) ?>
                        · past due since <?= View::date($debt['since']) ?> (<?= View::text((string) max(0, $days)) ?> days)
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($isOwner && $ready): ?>
                <p><a class="btn" href="<?= View::text($record) ?>/write-off">Write off</a></p>
            <?php endif; ?>
        <?php endif; ?>
        <?php foreach ($writeOffs as $written): ?>
            <div class="vendor-write-off">
                <p class="text-sm"><?= View::text($written['said']) ?></p>
                <?php if ($isOwner && $written['left'] > 0): ?>
                    <details class="text-sm">
                        <summary>They paid after this</summary>
                        <form class="row gap-2" method="post" action="<?= View::text($record) ?>/write-offs/<?= View::text($written['journal_id']) ?>/recover">
                            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                            <label for="recover-<?= View::text($written['journal_id']) ?>">Amount they paid (up to <?= View::text(View::money($written['left'])) ?>)</label>
                            <input class="input" id="recover-<?= View::text($written['journal_id']) ?>" name="amount" inputmode="decimal" required>
                            <button class="btn btn-sm" type="submit">Take it back</button>
                        </form>
                    </details>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
