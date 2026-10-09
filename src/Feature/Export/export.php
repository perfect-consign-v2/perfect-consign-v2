<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var string $from */
/** @var string $to */
$from = $from ?? '';
$to = $to ?? '';
?>
<h1>Download books</h1>
<form method="post" action="/<?= View::text($slug) ?>/export">
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
    <label for="from">From</label>
    <input id="from" name="from" type="date" required value="<?= View::text($from) ?>">
    <label for="to">To</label>
    <input id="to" name="to" type="date" required min="<?= View::text($from) ?>" value="<?= View::text($to) ?>">
    <label for="system">File</label>
    <select id="system" name="system">
        <option value="quickbooks">QuickBooks Online</option>
        <option value="xero">Xero</option>
    </select>
    <label for="tax_rate">Tax rate</label>
    <input id="tax_rate" name="tax_rate" value="Tax Exempt" required>
    <p><button type="submit">Download</button></p>
</form>
<script>
    var fromInput = document.getElementById('from');
    var toInput = document.getElementById('to');
    fromInput.addEventListener('change', function () {
        toInput.min = fromInput.value;
        if (toInput.value !== '' && toInput.value < fromInput.value) {
            toInput.value = fromInput.value;
        }
    });
</script>
