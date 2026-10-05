<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var list<array<string, mixed>> $vendors */
/** @var string $vendorId */
/** @var array{payable: int, rent: int, check: int, owes: int, settled: int, rent_from_sales: bool}|null $open */
/** @var string $runId */
/** @var array{by: string, reason: string, at: string}|null $undone */
/** @var string|null $error */

$paying = '';
$payName = '';
foreach ($vendors as $vendor) {
    if (($vendor['id'] ?? null) !== $vendorId) {
        continue;
    }
    $code = is_string($vendor['code'] ?? null) ? $vendor['code'] : '';
    $name = is_string($vendor['name'] ?? null) ? $vendor['name'] : '';
    $payName = $name;
    $paying = trim($name === '' ? $code : $name . ' (' . $code . ')');
    break;
}
$collect = '/' . $slug . '/rent?' . http_build_query(['q' => $payName !== '' ? $payName : $paying, 'standing' => 'owed']);
$canPay = $open !== null && ($open['check'] > 0 || $open['settled'] > 0);

?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Pay this vendor</h1>
        <?php if ($paying !== ''): ?>
            <p class="muted"><?= View::text($paying) ?></p>
        <?php endif; ?>
    </div>
</div>
<?php if (count($vendors) > 1): ?>
    <form method="get" action="/<?= View::text($slug) ?>/payout">
        <div class="field" style="max-width:24rem">
            <label for="vendor">Vendor</label>
            <select class="select" id="vendor" name="vendor" onchange="this.form.submit()">
                <?php foreach ($vendors as $vendor): ?>
                    <?php
                    $code = is_string($vendor['code'] ?? null) ? $vendor['code'] : '';
                    $name = is_string($vendor['name'] ?? null) ? $vendor['name'] : '';
                    $label = trim($name === '' ? $code : $name . ' (' . $code . ')');
                    ?>
                    <option value="<?= View::text($vendor['id']) ?>"<?= $vendor['id'] === $vendorId ? ' selected' : '' ?>><?= View::text($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
<?php endif; ?>
<?php if ($open === null): ?>
    <p class="muted mt-4">No open payout for this vendor.</p>
<?php else: ?>
    <div class="payout-figures">
        <div class="stat">
            <div class="stat-label">Their share of sales</div>
            <div class="stat-value"><?= View::text(View::money($open['payable'])) ?></div>
        </div>
        <?php if ($open['rent_from_sales']): ?>
            <div class="stat">
                <div class="stat-label">Rent from their share</div>
                <div class="stat-value"><?= View::text(View::money($open['settled'])) ?></div>
            </div>
        <?php elseif ($open['owes'] > 0): ?>
            <a class="stat stat-link stat-action" href="<?= View::text($collect) ?>">
                <div class="stat-label">Owes the store</div>
                <div class="stat-value"><?= View::text(View::money($open['owes'])) ?></div>
                <div class="stat-delta">Collect rent</div>
            </a>
        <?php else: ?>
            <div class="stat">
                <div class="stat-label">Open rent</div>
                <div class="stat-value"><?= View::text(View::money($open['rent'])) ?></div>
            </div>
        <?php endif; ?>
        <div class="stat payout-check">
            <div class="stat-label">We pay them</div>
            <div class="stat-value"><?= View::text(View::money($open['check'])) ?></div>
        </div>
        <?php if ($open['rent_from_sales'] && $open['owes'] > 0): ?>
            <a class="stat stat-link stat-action" href="<?= View::text($collect) ?>">
                <div class="stat-label">Owes the store</div>
                <div class="stat-value"><?= View::text(View::money($open['owes'])) ?></div>
                <div class="stat-delta">Collect rent</div>
            </a>
        <?php endif; ?>
    </div>
    <?php if ($canPay): ?>
        <p class="mt-4"><button class="btn btn-primary" type="button" data-open-modal="confirm-payout">Pay this vendor</button></p>
    <?php else: ?>
        <p class="muted mt-4">Nothing to pay.</p>
    <?php endif; ?>
    <div id="confirm-payout" class="modal" role="dialog" aria-modal="true" aria-labelledby="confirm-payout-title" hidden>
        <form class="modal-panel card" method="post" action="/<?= View::text($slug) ?>/payout">
            <div class="card-head"><div class="h3" id="confirm-payout-title">Confirm this payout</div></div>
            <div class="card-body stack">
                <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                <input type="hidden" name="vendor_id" value="<?= View::text($vendorId) ?>">
                <?php if ($paying !== ''): ?>
                    <p><?= View::text($paying) ?></p>
                <?php endif; ?>
                <p>Their share of sales <?= View::text(View::money($open['payable'])) ?></p>
                <?php if ($open['rent_from_sales']): ?>
                    <p>Rent from their share <?= View::text(View::money($open['settled'])) ?></p>
                <?php else: ?>
                    <p>Open rent <?= View::text(View::money($open['rent'])) ?></p>
                <?php endif; ?>
                <p class="h3">We pay them <?= View::text(View::money($open['check'])) ?></p>
                <?php if ($open['owes'] > 0): ?>
                    <p>Owes the store <?= View::text(View::money($open['owes'])) ?></p>
                <?php endif; ?>
                <div class="row gap-2">
                    <button class="btn btn-primary" type="submit">Pay this vendor</button>
                    <button class="btn" type="button" data-close-modal="confirm-payout">Cancel</button>
                </div>
            </div>
        </form>
    </div>
    <?php if (is_array($undone)): ?>
        <p class="muted mt-4">Undone by <?= View::text($undone['by']) ?> on <?= View::text($undone['at']) ?> — <?= View::text($undone['reason']) ?></p>
    <?php endif; ?>
    <?php if ($runId !== ''): ?>
        <section class="card mt-6">
            <div class="card-head"><div class="h3">Undo this payout</div></div>
            <form method="post" action="/<?= View::text($slug) ?>/payout/undo">
                <div class="card-body stack">
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <input type="hidden" name="vendor_id" value="<?= View::text($vendorId) ?>">
                    <input type="hidden" name="run_id" value="<?= View::text($runId) ?>">
                    <div class="field">
                        <label for="reason">Reason</label>
                        <input class="input" id="reason" name="reason" required>
                    </div>
                </div>
                <div class="card-foot">
                    <button class="btn" type="submit">Undo this payout</button>
                </div>
            </form>
        </section>
    <?php endif; ?>
<?php endif; ?>
