<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var string|null $error */
/** @var string $formToken */
/** @var string $today */
/** @var list<array{
 *     id: string,
 *     code: string,
 *     description: string,
 *     price_minor: int,
 *     quantity: int,
 *     expires_on: string,
 *     vendor_id: string,
 *     vendor_code: string,
 *     vendor_name: string,
 *     expiry_action: string
 * }> $items */
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Expired items</h1>
        <p class="muted">Return to the vendor, donate to store stock, or buy into store stock. Suggested action follows the vendor preference.</p>
    </div>
    <div class="row gap-2">
        <a class="btn" href="/<?= View::text($slug) ?>/inventory">All items</a>
    </div>
</div>

<?php if ($error !== null && $error !== ''): ?>
    <p class="error" role="alert"><?= View::text($error) ?></p>
<?php endif; ?>

<?php if ($items === []): ?>
    <p class="muted">No expired consigned pieces need a disposition.</p>
<?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Description</th>
                    <th>Vendor</th>
                    <th>Expired</th>
                    <th class="num">Price</th>
                    <th class="num">Qty</th>
                    <th>Clear</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <?php
                    $suggest = $item['expiry_action'];
                    $price = View::money($item['price_minor']);
                    ?>
                    <tr id="item-<?= View::text($item['id']) ?>">
                        <td><?= View::text($item['code']) ?></td>
                        <td><?= View::text($item['description']) ?></td>
                        <td><?= View::text($item['vendor_name'] !== '' ? $item['vendor_name'] : $item['vendor_code']) ?></td>
                        <td><?= View::date($item['expires_on']) ?></td>
                        <td class="num"><?= View::text($price) ?></td>
                        <td class="num"><?= View::text((string) $item['quantity']) ?></td>
                        <td>
                            <form class="row gap-2 flex-wrap" method="post" action="/<?= View::text($slug) ?>/inventory/<?= View::text($item['id']) ?>/dispose">
                                <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                <input type="hidden" name="form_token" value="<?= View::text($formToken) ?>">
                                <input type="hidden" name="posted_on" value="<?= View::text($today) ?>">
                                <label class="muted text-sm" for="amount-<?= View::text($item['id']) ?>">Buy $</label>
                                <input
                                    class="input"
                                    style="max-width:7rem"
                                    id="amount-<?= View::text($item['id']) ?>"
                                    name="amount"
                                    inputmode="decimal"
                                    value="<?= View::text($price) ?>"
                                    <?= $suggest === 'buy' ? 'required' : '' ?>
                                >
                                <button class="btn btn-sm<?= $suggest === 'return' ? ' btn-primary' : '' ?>" type="submit" name="kind" value="return">Return</button>
                                <button class="btn btn-sm<?= $suggest === 'donate' ? ' btn-primary' : '' ?>" type="submit" name="kind" value="donate">Donate</button>
                                <button class="btn btn-sm<?= $suggest === 'buy' ? ' btn-primary' : '' ?>" type="submit" name="kind" value="buy">Buy</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
