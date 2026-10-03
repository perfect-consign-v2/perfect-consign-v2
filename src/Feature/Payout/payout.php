<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var list<array<string, mixed>> $vendors */
/** @var string $vendorId */
/** @var array{payable: int, rent: int, check: int, owes: int}|null $open */
/** @var string|null $error */
?>
<h1>Pay this vendor</h1>
<?php if (is_string($error) && $error !== ''): ?>
    <p><?= View::text($error) ?></p>
<?php endif; ?>
<?php if (count($vendors) > 1): ?>
    <form method="get" action="/<?= View::text($slug) ?>/payout">
        <label for="vendor">Vendor</label>
        <select id="vendor" name="vendor" onchange="this.form.submit()">
            <?php foreach ($vendors as $vendor): ?>
                <option value="<?= View::text($vendor['id']) ?>"<?= $vendor['id'] === $vendorId ? ' selected' : '' ?>><?= View::text($vendor['code']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
<?php endif; ?>
<?php if ($open !== null): ?>
    <p>Their share <?= View::text(View::money($open['payable'])) ?></p>
    <p>Open rent <?= View::text(View::money($open['rent'])) ?></p>
    <?php if ($open['check'] > 0): ?>
        <p>Check <?= View::text(View::money($open['check'])) ?></p>
    <?php endif; ?>
    <?php if ($open['owes'] > 0): ?>
        <p>Amount owed to store <?= View::text(View::money($open['owes'])) ?></p>
    <?php endif; ?>
    <form method="post" action="/<?= View::text($slug) ?>/payout">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <input type="hidden" name="vendor_id" value="<?= View::text($vendorId) ?>">
        <p><button type="submit">Pay this vendor</button></p>
    </form>
<?php endif; ?>
