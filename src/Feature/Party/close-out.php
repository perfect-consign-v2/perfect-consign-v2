<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var string $record */
/** @var array{id: string, name: string, active: bool} $vendor */
/** @var list<array{id: string, code: string, description: string, price: int, quantity: int, on_sale: int}> $items */
/** @var list<array{item_id: string, code: string, description: string, noted_on: string, noted_by: string}> $held */
/** @var array<string, string> $open */
/** @var list<array{id: string, code: string, label: string}> $reasons */
/** @var string $keepFrom */
/** @var string $today */
/** @var bool $deposits */
/** @var string|null $error */

$first = explode(' ', $vendor['name'])[0];
$tookHome = null;
foreach ($reasons as $reason) {
    if ($reason['code'] === 'took_home') {
        $tookHome = $reason;
    }
}
$parts = [
    'items' => 'Items',
    'tickets' => 'Open sales',
    'booth' => 'Booth',
    'rent' => 'Rent',
    'clawback' => 'Returns',
    'payable' => 'Store owes ' . $first,
    'paid_ahead' => 'Paid ahead',
];
if ($deposits) {
    $parts['deposit'] = 'Deposit';
}
$canKeep = $keepFrom !== '' && $keepFrom <= $today;
$base = $record . '/close-out';
?>
<div class="page-head">
    <div class="titles">
        <nav class="breadcrumb">
            <a href="<?= View::text($record) ?>"><?= View::text($vendor['name']) ?></a><span class="sep">/</span>
            <span>Close out</span>
        </nav>
        <h1 class="h1">Close out <?= View::text($vendor['name']) ?></h1>
        <p class="muted">Count out what is left, then make <?= View::text($first) ?> inactive. Rent for the month they leave is the full month.</p>
    </div>
</div>

<?php if ($error !== null): ?>
    <div class="alert alert-danger" role="alert"><?= View::text($error) ?></div>
<?php endif; ?>

<section class="card" aria-labelledby="close-out-check">
    <div class="card-head"><div class="h3" id="close-out-check">Before <?= View::text($first) ?> can be made inactive</div></div>
    <div class="card-body">
        <ul class="close-out-check stack">
            <?php foreach ($parts as $key => $label): ?>
                <?php if (isset($open[$key])): ?>
                    <li class="close-out-open"><strong><?= View::text($label) ?>:</strong> <?= View::text($open[$key]) ?></li>
                <?php else: ?>
                    <li class="close-out-clear"><?= View::text($label) ?> <span aria-hidden="true">✓</span><span class="sr-only">clear</span></li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>
    </div>
    <div class="card-foot">
        <?php if (!$vendor['active']): ?>
            <p><?= View::text($vendor['name']) ?> is inactive.</p>
        <?php else: ?>
            <form method="post" action="<?= View::text($record) ?>/standing">
                <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                <input type="hidden" name="standing" value="inactive">
                <button class="btn btn-primary" type="submit"<?= $open === [] ? '' : ' disabled' ?>>Make inactive</button>
                <?php if ($open !== []): ?>
                    <span class="muted text-sm">This turns on when every part above has a check mark.</span>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>
</section>

<section class="card mt-6" aria-labelledby="close-out-items">
    <div class="card-head"><div class="h3" id="close-out-items">Items still in the store</div></div>
    <div class="card-body stack">
        <?php if ($items === []): ?>
            <p class="muted">No items are left. Nothing to count out.</p>
        <?php else: ?>
            <?php if ($tookHome !== null): ?>
                <form method="post" action="<?= View::text($base) ?>/all" class="row gap-2">
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <span><?= View::text($first) ?> took everything on the last day.</span>
                    <button class="btn btn-primary" type="submit">Mark all as <?= View::text($tookHome['label']) ?></button>
                </form>
            <?php endif; ?>
            <?php if ($keepFrom !== '' && !$canKeep): ?>
                <p class="muted text-sm">The store can keep what is left from <?= View::date($keepFrom) ?>.</p>
            <?php endif; ?>
            <table class="table">
                <thead><tr><th>Tag</th><th>Description</th><th class="num">Price</th><th class="num">Count</th><th>Why it is gone</th></tr></thead>
                <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= View::text($item['code']) ?></td>
                        <td><?= View::text($item['description']) ?><?php if ($item['on_sale'] > 0): ?> <span class="badge"><?= View::text((string) $item['on_sale']) ?> on an open sale</span><?php endif; ?></td>
                        <td class="num"><?= View::text(View::money($item['price'])) ?></td>
                        <td class="num"><?= View::text((string) $item['quantity']) ?></td>
                        <td>
                            <form method="post" action="<?= View::text($base) ?>/count" class="row gap-2">
                                <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                <input type="hidden" name="item_id" value="<?= View::text($item['id']) ?>">
                                <?php foreach ($reasons as $reason): ?>
                                    <button class="btn btn-sm" type="submit" name="quantity_reason" value="<?= View::text($reason['id']) ?>"><?= View::text($reason['label']) ?></button>
                                <?php endforeach; ?>
                            </form>
                            <?php if ($canKeep): ?>
                                <form method="post" action="<?= View::text($base) ?>/keep" class="mt-2">
                                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                    <input type="hidden" name="item_id" value="<?= View::text($item['id']) ?>">
                                    <button class="btn btn-sm" type="submit">The store keeps it</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</section>

<?php if ($held !== []): ?>
<section class="card mt-6" aria-labelledby="close-out-held">
    <div class="card-head"><div class="h3" id="close-out-held">Held for <?= View::text($first) ?></div></div>
    <div class="card-body">
        <p class="muted text-sm">These turned up after <?= View::text($first) ?> left. Give them back when <?= View::text($first) ?> comes in.</p>
        <table class="table">
            <thead><tr><th>Tag</th><th>Description</th><th>Held since</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($held as $piece): ?>
                <tr>
                    <td><?= View::text($piece['code']) ?></td>
                    <td><?= View::text($piece['description']) ?></td>
                    <td><?= View::date($piece['noted_on']) ?> by <?= View::text($piece['noted_by']) ?></td>
                    <td>
                        <form method="post" action="<?= View::text($base) ?>/picked-up">
                            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                            <input type="hidden" name="item_id" value="<?= View::text($piece['item_id']) ?>">
                            <button class="btn btn-sm" type="submit"><?= View::text($first) ?> picked it up</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
