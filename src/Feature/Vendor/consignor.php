<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var array{
 *     items: list<array<string, mixed>>,
 *     sales: list<array<string, mixed>>,
 *     payable: int,
 *     expiring: list<array<string, mixed>>,
 *     agreement: array<string, mixed>
 * } $page */
$agreement = $page['agreement'];
$mode = is_string($agreement['payout_mode'] ?? null) ? $agreement['payout_mode'] : 'pay';
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Your account</h1>
    </div>
    <form method="post" action="/<?= View::text($slug) ?>/consignor/logout">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <button class="btn" type="submit">Sign out</button>
    </form>
</div>
<section class="card">
    <div class="card-body stack">
        <h2 class="h3">Agreement</h2>
        <p>Starts <?= View::text(is_string($agreement['agreement_starts_on'] ?? null) ? $agreement['agreement_starts_on'] : '—') ?></p>
        <p>Ends <?= View::text(is_string($agreement['agreement_ends_on'] ?? null) ? $agreement['agreement_ends_on'] : '—') ?></p>
        <p>Open payable <?= View::text(View::money($page['payable'])) ?></p>
        <form method="post" action="/<?= View::text($slug) ?>/consignor/preference">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <label for="payout_mode">Payout</label>
            <select class="input" id="payout_mode" name="payout_mode">
                <option value="pay"<?= $mode === 'pay' ? ' selected' : '' ?>>Check</option>
                <option value="hold"<?= $mode === 'hold' ? ' selected' : '' ?>>Hold</option>
            </select>
            <button class="btn" type="submit">Save preference</button>
        </form>
    </div>
</section>
<section class="card">
    <div class="card-body">
        <h2 class="h3">Items</h2>
        <ul>
            <?php foreach ($page['items'] as $item): ?>
                <li><?= View::text(is_string($item['description'] ?? null) ? $item['description'] : '') ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>
<section class="card">
    <div class="card-body">
        <h2 class="h3">Sales</h2>
        <ul>
            <?php foreach ($page['sales'] as $sale): ?>
                <li><?= View::text(is_string($sale['posting_date'] ?? null) ? $sale['posting_date'] : '') ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>
<section class="card">
    <div class="card-body">
        <h2 class="h3">About to expire</h2>
        <ul>
            <?php foreach ($page['expiring'] as $item): ?>
                <li><?= View::text(is_string($item['description'] ?? null) ? $item['description'] : '') ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>
