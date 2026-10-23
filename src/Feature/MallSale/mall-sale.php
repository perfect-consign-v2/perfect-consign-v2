<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var list<array{name: string, starts_on: string, ends_on: string, percent_bps: int, absorbed_by: string}> $events */
?>
<h1>Mall sale</h1>
<p>The register applies the newest event that covers today. Mall means the store absorbs it. Vendor means it comes out of the vendor's price.</p>
<form method="post" action="/<?= View::text($slug) ?>/mall-sale">
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
    <label for="name">Name</label>
    <input id="name" name="name" required maxlength="80">
    <label for="starts_on">From</label>
    <input id="starts_on" name="starts_on" type="date" required>
    <label for="ends_on">To</label>
    <input id="ends_on" name="ends_on" type="date" required>
    <label for="percent">Percent off</label>
    <input id="percent" name="percent" inputmode="decimal" required>
    <label for="absorbed_by">Absorbed by</label>
    <select id="absorbed_by" name="absorbed_by">
        <option value="mall">Mall</option>
        <option value="vendor">Vendor</option>
    </select>
    <button type="submit">Save</button>
</form>
<ul>
    <?php foreach ($events as $event): ?>
        <li><?= View::text($event['name']) ?> <?= View::text($event['starts_on']) ?> to <?= View::text($event['ends_on']) ?> <?= View::text((string) ($event['percent_bps'] / 100)) ?>% <?= View::text($event['absorbed_by']) ?></li>
    <?php endforeach; ?>
</ul>
