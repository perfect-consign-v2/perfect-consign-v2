<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $csrf */
/** @var string $base */
/** @var string $id */
/** @var bool $locked */
/** @var string|null $error */
/** @var array{name: string, scope: string, kind: string, threshold: string, price: string, funded_by: string, starts_on: string, ends_on: string, item_code: string, vendor_id: string} $draft */
/** @var list<array{id: string, code: string, name: string}> $vendors */

$action = $id === '' ? $base : $base . '/' . $id;
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1"><?= $id === '' ? 'New deal' : 'Edit deal' ?></h1>
        <p class="muted">Three for $25 leaves one or two at the tag price. N or more reprices every unit on the line.</p>
    </div>
    <a class="btn" href="<?= View::text($base) ?>">Back</a>
</div>
<form class="card mt-6" method="post" action="<?= View::text($action) ?>">
    <div class="card-body stack">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <label for="name">Name</label>
        <input class="input" id="name" name="name" value="<?= View::text($draft['name']) ?>" required maxlength="80">
        <label for="scope">Applies to</label>
        <select class="input" id="scope" name="scope">
            <option value="item"<?= $draft['scope'] === 'item' ? ' selected' : '' ?>><?= $locked ? 'One of my items' : 'One item' ?></option>
            <option value="vendor"<?= $draft['scope'] === 'vendor' ? ' selected' : '' ?>><?= $locked ? 'All my items' : 'One vendor' ?></option>
            <?php if (!$locked): ?>
                <option value="store"<?= $draft['scope'] === 'store' ? ' selected' : '' ?>>Whole store</option>
            <?php endif; ?>
        </select>
        <label for="item_code">Item tag</label>
        <input class="input" id="item_code" name="item_code" value="<?= View::text($draft['item_code']) ?>" autocomplete="off">
        <?php if (!$locked): ?>
            <label for="vendor_id">Vendor</label>
            <select class="input" id="vendor_id" name="vendor_id">
                <option value="">Choose</option>
                <?php foreach ($vendors as $vendor): ?>
                    <option value="<?= View::text($vendor['id']) ?>"<?= $draft['vendor_id'] === $vendor['id'] ? ' selected' : '' ?>><?= View::text($vendor['code'] . ' ' . $vendor['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <label for="funded_by">Who pays</label>
            <select class="input" id="funded_by" name="funded_by">
                <option value="vendor"<?= $draft['funded_by'] !== 'store' ? ' selected' : '' ?>>Vendor — the check uses the lower price</option>
                <option value="store"<?= $draft['funded_by'] === 'store' ? ' selected' : '' ?>>Store — the vendor's check stays on the tag price</option>
            </select>
            <p class="muted">A store-wide deal is usually paid by the store.</p>
        <?php else: ?>
            <input type="hidden" name="funded_by" value="vendor">
            <input type="hidden" name="vendor_id" value="">
        <?php endif; ?>
        <label for="kind">Kind</label>
        <select class="input" id="kind" name="kind">
            <option value="bundle"<?= $draft['kind'] !== 'unit_break' ? ' selected' : '' ?>>N for a price</option>
            <option value="unit_break"<?= $draft['kind'] === 'unit_break' ? ' selected' : '' ?>>N or more at a unit price</option>
        </select>
        <label for="threshold">Quantity</label>
        <input class="input" id="threshold" name="threshold" inputmode="numeric" value="<?= View::text($draft['threshold']) ?>" required>
        <label for="price">Deal price</label>
        <input class="input" id="price" name="price" inputmode="decimal" value="<?= View::text($draft['price']) ?>" required>
        <label for="starts_on">Starts</label>
        <input class="input" id="starts_on" name="starts_on" type="date" value="<?= View::text($draft['starts_on']) ?>" required>
        <label for="ends_on">Ends</label>
        <input class="input" id="ends_on" name="ends_on" type="date" value="<?= View::text($draft['ends_on']) ?>">
        <button class="btn btn-primary" type="submit">Save deal</button>
    </div>
</form>
<?php if (!$locked): ?>
<script>
document.getElementById('scope').addEventListener('change', function () {
    const who = document.getElementById('funded_by');
    if (!who) {
        return;
    }
    who.value = this.value === 'store' ? 'store' : 'vendor';
});
</script>
<?php endif; ?>
