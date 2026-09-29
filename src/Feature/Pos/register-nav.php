<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $displayName */
/** @var array{parked: list<array{status: string}>} $cart */
/** @var string $giftNumber */
/** @var ?int $giftBalance */

$giftNumber = $giftNumber ?? '';
$giftBalance = $giftBalance ?? null;
$parkedCount = 0;
$layawayCount = 0;
foreach ($cart['parked'] as $held) {
    if ($held['status'] === 'layaway') {
        $layawayCount++;
        continue;
    }
    $parkedCount++;
}
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1"><?= View::text($displayName) ?></h1>
        <p class="muted">Point of Sale</p>
    </div>
    <nav class="register-nav" aria-label="Register">
        <a class="btn" href="/<?= View::text($slug) ?>/journals">Sales</a>
        <a class="btn" href="/<?= View::text($slug) ?>/pos/layaways">Layaways (<?= View::text((string) $layawayCount) ?>)</a>
        <a class="btn" href="/<?= View::text($slug) ?>/pos/suspended">Suspended Sales (<?= View::text((string) $parkedCount) ?>)</a>
        <button class="btn" type="button" data-open-modal="gift-balance">Gift Certificate Balance</button>
    </nav>
</div>
<div id="gift-balance" class="modal" role="dialog" aria-modal="true" aria-labelledby="gift-balance-title" <?= $giftNumber === '' ? 'hidden' : '' ?>>
    <form class="modal-panel card" method="get" action="/<?= View::text($slug) ?>/pos">
        <div class="card-head"><div class="h3" id="gift-balance-title">Gift certificate balance</div></div>
        <div class="card-body stack">
            <label for="gift">Gift balance</label>
            <input class="input" id="gift" name="gift" value="<?= View::text($giftNumber) ?>" autocomplete="off">
            <button class="btn btn-primary" type="submit">Look up</button>
            <?php if ($giftNumber !== ''): ?>
                <p><?php if ($giftBalance === null): ?>That certificate is not on file.<?php else: ?>Balance left <?= View::text(View::money($giftBalance)) ?><?php endif; ?></p>
            <?php endif; ?>
            <button class="btn" type="button" data-close-modal="gift-balance">Close</button>
        </div>
    </form>
</div>
