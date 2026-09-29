<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var list<array<string, mixed>> $booths */
/** @var string $today */
?>
<h1>Rent</h1>
<div class="card">
    <h2>Charge the stored rate</h2>
    <form method="post" action="/<?= View::text($slug) ?>/rent/charge">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <label for="charge_booth">Booth</label>
        <select id="charge_booth" name="booth_id" required>
            <option value="">Choose a booth</option>
            <?php foreach ($booths as $booth): ?>
                <option value="<?= View::text($booth['id']) ?>"><?= View::text($booth['code']) ?></option>
            <?php endforeach; ?>
        </select>
        <label for="charge_date">Posting date</label>
        <input id="charge_date" name="posting_date" type="date" value="<?= View::text($today) ?>" required>
        <p><button type="submit">Post rent charge</button></p>
    </form>
</div>
<div class="card">
    <h2>Rent payment</h2>
    <form method="post" action="/<?= View::text($slug) ?>/rent/payment">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <label for="pay_reference">Source reference</label>
        <input id="pay_reference" name="source_reference" required>
        <label for="pay_booth">Booth</label>
        <select id="pay_booth" name="booth_id" required>
            <option value="">Choose a booth</option>
            <?php foreach ($booths as $booth): ?>
                <option value="<?= View::text($booth['id']) ?>"><?= View::text($booth['code']) ?></option>
            <?php endforeach; ?>
        </select>
        <label for="pay_amount">Amount</label>
        <input id="pay_amount" name="amount" inputmode="decimal" required>
        <label for="pay_date">Posting date</label>
        <input id="pay_date" name="posting_date" type="date" value="<?= View::text($today) ?>" required>
        <p><button type="submit">Post rent payment</button></p>
    </form>
</div>
