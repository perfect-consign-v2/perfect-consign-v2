<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var ?string $error */
?>
<h1>Booth extras</h1>
<?php if ($error !== null && $error !== ''): ?>
    <p><?= View::text($error) ?></p>
<?php endif; ?>
<form method="post" action="/<?= View::text($slug) ?>/booth-extras">
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
    <p>Electric or advertising. This charges the booth and credits booth extras.</p>
    <label for="booth_id">Booth id</label>
    <input id="booth_id" name="booth_id" required>
    <label for="source_reference">Reference</label>
    <input id="source_reference" name="source_reference" required>
    <label for="amount">Amount</label>
    <input id="amount" name="amount" inputmode="decimal" required>
    <label for="posting_date">Day</label>
    <input id="posting_date" name="posting_date" type="date" required>
    <button type="submit">Charge</button>
</form>
<form method="post" action="/<?= View::text($slug) ?>/booth-deposit">
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
    <p>A deposit collected at the desk. Cash in, deposit liability up.</p>
    <label for="deposit_booth">Booth id</label>
    <input id="deposit_booth" name="booth_id" required>
    <label for="deposit_reference">Reference</label>
    <input id="deposit_reference" name="source_reference" required>
    <label for="deposit_amount">Amount</label>
    <input id="deposit_amount" name="amount" inputmode="decimal" required>
    <label for="deposit_date">Day</label>
    <input id="deposit_date" name="posting_date" type="date" required>
    <button type="submit">Collect deposit</button>
</form>
<form method="post" action="/<?= View::text($slug) ?>/booth-deposit/release">
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
    <p>Give a deposit back. Cash out, deposit liability down. The open register has to be the one at the desk.</p>
    <label for="release_booth">Booth id</label>
    <input id="release_booth" name="booth_id" required>
    <label for="release_reference">Reference</label>
    <input id="release_reference" name="source_reference" required>
    <label for="release_amount">Amount</label>
    <input id="release_amount" name="amount" inputmode="decimal" required>
    <label for="release_date">Day</label>
    <input id="release_date" name="posting_date" type="date" required>
    <button type="submit">Return deposit</button>
</form>
