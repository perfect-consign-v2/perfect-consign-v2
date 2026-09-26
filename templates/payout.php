<?php

declare(strict_types=1);

use PerfectConsign\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var list<array<string, mixed>> $vendors */
/** @var string $vendorId */
/** @var array{payable: int, rent: int, booths: list<array{booth_id: string, open_minor: int}>}|null $open */
/** @var list<array<string, mixed>> $statement */
?>
<h1>Payout</h1>
<form method="get" action="/<?= View::text($slug) ?>/payout">
    <label for="vendor">Vendor</label>
    <select id="vendor" name="vendor">
        <?php foreach ($vendors as $vendor): ?>
            <option value="<?= View::text($vendor['id']) ?>"<?= $vendor['id'] === $vendorId ? ' selected' : '' ?>><?= View::text($vendor['code']) ?></option>
        <?php endforeach; ?>
    </select>
    <p><button type="submit">Show open balances</button></p>
</form>
<?php if ($open !== null): ?>
    <p>Open payable <?= View::text(View::money($open['payable'])) ?></p>
    <p>Open rent <?= View::text(View::money($open['rent'])) ?></p>
    <form method="post" action="/<?= View::text($slug) ?>/payout">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <input type="hidden" name="vendor_id" value="<?= View::text($vendorId) ?>">
        <label for="month">Month</label>
        <input id="month" name="month" placeholder="YYYY-MM" required>
        <label for="posting_date">Posting date</label>
        <input id="posting_date" name="posting_date" type="date" required>
        <p><button type="submit">Post payout</button></p>
    </form>
    <h2>Statement</h2>
    <table>
        <thead><tr><th>Date</th><th>No</th><th>Source</th><th>Key</th><th>Role</th><th>Debit</th><th>Credit</th></tr></thead>
        <tbody>
        <?php foreach ($statement as $row): ?>
            <tr>
                <td><?= View::text($row['posting_date']) ?></td>
                <td><?= View::text($row['journal_no']) ?></td>
                <td><?= View::text($row['source_type']) ?></td>
                <td><?= View::text($row['posting_key']) ?></td>
                <td><?= View::text($row['role']) ?></td>
                <td><?= View::text(View::money((int) $row['debit_minor'])) ?></td>
                <td><?= View::text(View::money((int) $row['credit_minor'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
