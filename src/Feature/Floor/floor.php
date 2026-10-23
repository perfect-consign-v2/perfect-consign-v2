<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var list<array<string, string>> $findings */
/** @var list<array<string, string>> $pickups */
?>
<h1>Floor</h1>
<form method="post" action="/<?= View::text($slug) ?>/floor">
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
    <label for="code">Item tag</label>
    <input id="code" name="code" required>
    <label for="kind">Finding</label>
    <select id="kind" name="kind">
        <option value="missing">Missing</option>
        <option value="misplaced">Not where the system says</option>
    </select>
    <label for="noted_on">Day</label>
    <input id="noted_on" name="noted_on" type="date" required>
    <label for="noted_by">Noted by</label>
    <input id="noted_by" name="noted_by" required maxlength="80">
    <button type="submit">Save finding</button>
</form>
<form method="post" action="/<?= View::text($slug) ?>/pickups">
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
    <label for="pickup_code">Item tag</label>
    <input id="pickup_code" name="code" required>
    <label for="picked_up_on">Picked up</label>
    <input id="picked_up_on" name="picked_up_on" type="date" required>
    <label for="received_by">Received by</label>
    <input id="received_by" name="received_by" required maxlength="80">
    <button type="submit">Save pickup</button>
</form>
<h2>Findings</h2>
<ul>
    <?php foreach ($findings as $row): ?>
        <li><?= View::text($row['noted_on'] ?? '') ?> <?= View::text($row['code'] ?? '') ?> <?= View::text($row['kind'] ?? '') ?> <?= View::text($row['noted_by'] ?? '') ?></li>
    <?php endforeach; ?>
</ul>
<h2>Pickups</h2>
<ul>
    <?php foreach ($pickups as $row): ?>
        <li><?= View::text($row['picked_up_on'] ?? '') ?> <?= View::text($row['code'] ?? '') ?> <?= View::text($row['name'] ?? '') ?> <?= View::text($row['received_by'] ?? '') ?></li>
    <?php endforeach; ?>
</ul>
