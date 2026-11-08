<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var array{id: string, name: string, phone: string, email: string} $customer */
/** @var string|null $error */
/** @var string $sellerList */
/** @var string $sellerRoleAction */

$sellerList = is_string($sellerList ?? null) ? $sellerList : '';
$sellerRoleAction = is_string($sellerRoleAction ?? null) && $sellerRoleAction !== ''
    ? $sellerRoleAction
    : '/' . $slug . '/customers/' . $customer['id'] . '/' . (str_contains($sellerList, 'consignors') ? 'consignors-role' : 'vendor-role');
$sellerRoleLabel = str_contains($sellerRoleAction, 'consignor') ? 'Add consignor role' : 'Add vendor role';
?>
<div class="page-head">
    <div class="titles">
        <nav class="crumbs" aria-label="Breadcrumb">
            <a href="/<?= View::text($slug) ?>/customers">Customers</a><span class="sep">/</span>
            <span><?= View::text($customer['name']) ?></span>
        </nav>
        <h1 class="h1">Customer</h1>
        <p class="muted">Contact on the party. Add a vendor/consignor role without cloning this person.</p>
    </div>
</div>
<?php if (is_string($error) && $error !== ''): ?>
    <p class="flash flash-error mt-4"><?= View::text($error) ?></p>
<?php endif; ?>
<form class="stack card mt-6" method="post" action="/<?= View::text($slug) ?>/customers/<?= View::text($customer['id']) ?>">
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
    <div class="field">
        <label for="customer_name">Name</label>
        <input class="input" id="customer_name" name="customer_name" value="<?= View::text($customer['name']) ?>" required>
    </div>
    <div class="field">
        <label for="customer_phone">Phone</label>
        <input class="input" id="customer_phone" name="customer_phone" value="<?= View::text($customer['phone']) ?>">
    </div>
    <div class="field">
        <label for="customer_email">Email</label>
        <input class="input" id="customer_email" name="customer_email" value="<?= View::text($customer['email']) ?>">
    </div>
    <button class="btn btn-primary" type="submit">Save customer</button>
</form>
<form class="stack card mt-6" method="post" action="<?= View::text($sellerRoleAction) ?>">
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
    <h2 class="h3">Also a seller</h2>
    <p class="muted text-sm">Attaches the seller role on this same party. Use <a href="<?= View::text($sellerList) ?>">the seller list</a> after.</p>
    <div class="field">
        <label for="code">Seller code</label>
        <input class="input" id="code" name="code" required>
    </div>
    <button class="btn" type="submit"><?= View::text($sellerRoleLabel) ?></button>
</form>
