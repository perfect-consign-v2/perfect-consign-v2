<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var list<array<string, mixed>> $vendors */
/** @var list<array<string, mixed>> $booths */
/** @var list<array<string, mixed>> $assignments */
/** @var list<array<string, mixed>> $rates */
?>
<h1>Vendors and booths</h1>
<div class="card">
    <h2>Vendor</h2>
    <form method="post" action="/<?= View::text($slug) ?>/vendors">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <label for="vendor_code">Code</label>
        <input id="vendor_code" name="code" required>
        <label for="vendor_name">Name</label>
        <input id="vendor_name" name="name" required>
        <p><button type="submit">Add vendor</button></p>
    </form>
    <ul>
        <?php foreach ($vendors as $vendor): ?>
            <li><?= View::text($vendor['code']) ?> <?= View::text($vendor['name']) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<div class="card">
    <h2>Booth</h2>
    <form method="post" action="/<?= View::text($slug) ?>/booths">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <label for="booth_code">Code</label>
        <input id="booth_code" name="code" required>
        <label for="booth_name">Name</label>
        <input id="booth_name" name="name" required>
        <p><button type="submit">Add booth</button></p>
    </form>
    <ul>
        <?php foreach ($booths as $booth): ?>
            <li><?= View::text($booth['code']) ?> <?= View::text($booth['name']) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<div class="card">
    <h2>Assignment</h2>
    <form method="post" action="/<?= View::text($slug) ?>/assignments">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <label for="assign_booth">Booth</label>
        <select id="assign_booth" name="booth_id" required>
            <?php foreach ($booths as $booth): ?>
                <option value="<?= View::text($booth['id']) ?>"><?= View::text($booth['code']) ?></option>
            <?php endforeach; ?>
        </select>
        <label for="assign_vendor">Vendor</label>
        <select id="assign_vendor" name="vendor_id" required>
            <?php foreach ($vendors as $vendor): ?>
                <option value="<?= View::text($vendor['id']) ?>"><?= View::text($vendor['code']) ?></option>
            <?php endforeach; ?>
        </select>
        <label for="starts_on">Start</label>
        <input id="starts_on" name="starts_on" type="date" required>
        <label for="ends_on">End</label>
        <input id="ends_on" name="ends_on" type="date">
        <p><button type="submit">Add assignment</button></p>
    </form>
    <table>
        <thead><tr><th>Booth</th><th>Vendor</th><th>Start</th><th>End</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($assignments as $assignment): ?>
            <tr>
                <td><?= View::text($assignment['booth_code']) ?></td>
                <td><?= View::text($assignment['vendor_code']) ?></td>
                <td><?= View::text($assignment['starts_on']) ?></td>
                <td><?= View::text($assignment['ends_on']) ?></td>
                <td>
                    <?php if ($assignment['ends_on'] === null): ?>
                        <form method="post" action="/<?= View::text($slug) ?>/assignments/<?= View::text($assignment['id']) ?>/end">
                            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                            <input name="ends_on" type="date" required min="<?= View::text($assignment['starts_on']) ?>">
                            <button type="submit">End</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<div class="card">
    <h2>Monthly rent rate</h2>
    <form method="post" action="/<?= View::text($slug) ?>/rates">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <label for="rate_booth">Booth</label>
        <select id="rate_booth" name="booth_id" required>
            <?php foreach ($booths as $booth): ?>
                <option value="<?= View::text($booth['id']) ?>"><?= View::text($booth['code']) ?></option>
            <?php endforeach; ?>
        </select>
        <label for="month_start">Month</label>
        <input id="month_start" name="month_start" type="date" required>
        <label for="amount">Amount</label>
        <input id="amount" name="amount" inputmode="decimal" required>
        <p><button type="submit">Add rate</button></p>
    </form>
    <ul>
        <?php foreach ($rates as $rate): ?>
            <li><?= View::text($rate['code']) ?> <?= View::text($rate['month_start']) ?> <?= View::text(View::money((int) $rate['amount_minor'])) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
