<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var int $openTax */
/** @var string $today */
?>
<h1>Sales tax</h1>
<p>Open tax <?= View::text(View::money($openTax)) ?></p>
<form method="post" action="/<?= View::text($slug) ?>/tax">
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
    <label for="source_reference">Source reference</label>
    <input id="source_reference" name="source_reference" required>
    <label for="amount">Amount</label>
    <input id="amount" name="amount" inputmode="decimal" required>
    <label for="credit">Credit</label>
    <select id="credit" name="credit">
        <option value="cash">Cash</option>
        <option value="bank">Bank</option>
    </select>
    <label for="posting_date">Posting date</label>
    <input id="posting_date" name="posting_date" type="date" value="<?= View::text($today) ?>" required>
    <p><button type="submit">Post tax payment</button></p>
</form>
