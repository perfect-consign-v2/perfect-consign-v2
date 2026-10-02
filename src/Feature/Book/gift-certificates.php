<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var int $count */
/** @var int $total */
/** @var list<array{number: string, journal_id: string, sold_on: string, issued: int, balance: int}> $rows */
?>
<h1>Open gift certificates</h1>
<p><?= View::text((string) $count) ?> open. Balance left <?= View::text(View::money($total)) ?></p>
<?php if ($rows === []): ?>
    <p>No open gift certificates.</p>
<?php else: ?>
    <table>
        <thead><tr><th>Number</th><th>Sold</th><th>Issued</th><th>Balance left</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= View::text($row['number']) ?></td>
                <td><?= View::text($row['sold_on']) ?></td>
                <td><?= View::text(View::money($row['issued'])) ?></td>
                <td><?= View::text(View::money($row['balance'])) ?></td>
                <td><a href="/<?= View::text($slug) ?>/pos/gift/<?= View::text($row['journal_id']) ?>">Print certificate</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
