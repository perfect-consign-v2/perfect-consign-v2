<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var string|null $error */
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Bring in tags</h1>
        <p class="muted">One row is one tag on the floor. This does not import old sales or balances.</p>
    </div>
</div>
<div class="card mt-6">
    <form class="card-body stack" method="post" action="/<?= View::text($slug) ?>/inventory/import" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <p class="muted text-sm">The first row is code, vendor, description, price, barcode, quantity. Vendor is the consignor code. Price is dollars. Quantity can be empty, and then the tag is one item. Bring in 500 tags at a time.</p>
        <div class="field">
            <label for="tags">CSV file</label>
            <input class="input" id="tags" name="tags" type="file" accept=".csv,text/csv" required>
        </div>
        <?php if (is_string($error) && $error !== ''): ?>
            <p class="error"><?= View::text($error) ?></p>
        <?php endif; ?>
        <div class="row gap-2">
            <button class="btn btn-primary" type="submit">Bring in tags</button>
            <a class="btn" href="/<?= View::text($slug) ?>/inventory">Cancel</a>
        </div>
    </form>
</div>
