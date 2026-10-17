<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $monthName */
/** @var array{sales: int, tax: int, rent: int, checks: int} $figures */
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1"><?= View::text($monthName) ?></h1>
        <p class="muted">Rent still owed is on <a href="/<?= View::text($slug) ?>/rent">Rent</a>. Checks are on <a href="/<?= View::text($slug) ?>/payouts">Pay vendors</a>.</p>
    </div>
</div>
<div class="grid cols-4 mt-6">
    <div class="stat">
        <div class="stat-label">Sales</div>
        <div class="stat-value"><?= View::text(View::money($figures['sales'])) ?></div>
    </div>
    <div class="stat">
        <div class="stat-label">Tax collected</div>
        <div class="stat-value"><?= View::text(View::money($figures['tax'])) ?></div>
    </div>
    <div class="stat">
        <div class="stat-label">Rent still owed</div>
        <div class="stat-value"><?= View::text(View::money($figures['rent'])) ?></div>
    </div>
    <div class="stat">
        <div class="stat-label">Checks written</div>
        <div class="stat-value"><?= View::text(View::money($figures['checks'])) ?></div>
    </div>
</div>
