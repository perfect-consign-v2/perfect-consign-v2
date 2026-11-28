<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var string $record */
/** @var array{id: string, name: string, active: bool} $vendor */
/** @var list<array{kind: string, booth: string, open: int, since: string, eligible_on: string}> $debts */
/** @var array<string, string> $open */
/** @var string $today */
/** @var string $reason */
/** @var string|null $error */

$first = explode(' ', $vendor['name'])[0];
$total = 0;
$ready = true;
foreach ($debts as $debt) {
    $total += $debt['open'];
    if ($debt['eligible_on'] > $today) {
        $ready = false;
    }
}
?>
<div class="page-head">
    <div class="titles">
        <nav class="breadcrumb">
            <a href="<?= View::text($record) ?>"><?= View::text($vendor['name']) ?></a><span class="sep">/</span>
            <span>Write off</span>
        </nav>
        <h1 class="h1">Write off what <?= View::text($first) ?> owes</h1>
    </div>
</div>

<?php if ($error !== null): ?>
    <div class="alert alert-danger" role="alert"><?= View::text($error) ?></div>
<?php endif; ?>

<?php if ($debts === []): ?>
    <div class="card"><div class="card-body"><p><?= View::text($vendor['name']) ?> owes the store nothing.</p></div></div>
<?php else: ?>
<section class="card" aria-labelledby="write-off-debts">
    <div class="card-head"><div class="h3" id="write-off-debts">What <?= View::text($first) ?> owes</div></div>
    <div class="card-body">
        <table class="table">
            <thead><tr><th>What</th><th class="num">Amount</th><th>Past due since</th><th>Can be written off from</th></tr></thead>
            <tbody>
            <?php foreach ($debts as $debt): ?>
                <tr>
                    <td><?= View::text($debt['kind'] === 'rent' ? 'Rent for booth ' . $debt['booth'] : 'Returns after a payout') ?></td>
                    <td class="num"><?= View::text(View::money($debt['open'])) ?></td>
                    <td><?= View::date($debt['since']) ?></td>
                    <td><?= $debt['eligible_on'] === '' ? View::text('—') : View::date($debt['eligible_on']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if (isset($open['payable']) && str_starts_with($open['payable'], 'The store owes')): ?>
    <div class="alert alert-warning mt-4" role="status">
        <?= View::text($open['payable']) ?> Apply that first: pay <?= View::text($first) ?> or take it toward what they owe at the next payout.
        <a href="/<?= View::text($slug) ?>/payouts">Pay vendors</a>
    </div>
<?php elseif (!$ready): ?>
    <p class="muted mt-4">A debt can be written off once it has been past due for the waiting days in Settings → Bad debts.</p>
<?php else: ?>
<form class="card mt-6" method="post" action="<?= View::text($record) ?>/write-off">
    <div class="card-body stack">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <p><?= View::text($first) ?> owes <?= View::text(View::money($total)) ?>. Writing it off records it as a loss. If <?= View::text($first) ?> pays later, you can still take the money.</p>
        <div class="field">
            <label for="reason">Why</label>
            <textarea class="input" id="reason" name="reason" maxlength="500" rows="2" required><?= View::text($reason) ?></textarea>
        </div>
    </div>
    <div class="card-foot"><button class="btn btn-primary" type="submit">Write off <?= View::text(View::money($total)) ?></button></div>
</form>
<?php endif; ?>
<?php endif; ?>
