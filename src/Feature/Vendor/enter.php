<?php

declare(strict_types=1);

use PerfectConsign\Feature\Vendor\VendorEntry;
use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var string|null $error */
/** @var string|null $notice */
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Consignor sign in</h1>
        <p class="muted">We email a link. The link does not say whether the address is on file.</p>
    </div>
</div>
<?php if ($error !== null && $error !== ''): ?>
    <p class="form-error"><?= View::text($error) ?></p>
<?php endif; ?>
<?php if ($notice !== null && $notice !== ''): ?>
    <p><?= View::text($notice) ?></p>
<?php endif; ?>
<form class="stack" method="post" action="/<?= View::text($slug) ?>/consignor/enter">
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
    <label for="email">Email</label>
    <input class="input" id="email" name="email" type="email" autocomplete="email" required>
    <button class="btn btn-primary" type="submit">Email the link</button>
</form>
<p class="muted"><?= View::text(VendorEntry::SENT) ?></p>
