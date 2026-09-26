<?php

declare(strict_types=1);

use PerfectConsign\Http\View;

/** @var string $slug */
/** @var list<array<string, mixed>> $journals */
?>
<h1>Journals</h1>
<table>
    <thead><tr><th>No</th><th>Date</th><th>Key</th><th>Source</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($journals as $journal): ?>
        <tr>
            <td><?= View::text($journal['journal_no']) ?></td>
            <td><?= View::text($journal['posting_date']) ?></td>
            <td><?= View::text($journal['posting_key']) ?></td>
            <td><?= View::text($journal['source_type']) ?></td>
            <td><a href="/<?= View::text($slug) ?>/journals/<?= View::text($journal['id']) ?>">Open</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
