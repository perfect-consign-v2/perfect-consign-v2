<?php

declare(strict_types=1);

use PerfectConsign\Ledger\SourceLabel;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Web\ListTable;

/** @var string $slug */
/** @var list<array<string, mixed>> $journals */
?>
<h1>Money record</h1>
<div class="list-dt-wrap">
<?php
ListTable::open(['id' => 'journals', 'export' => 'journals']);
ListTable::head([
    ['label' => 'No'],
    ['label' => 'Date'],
    ['label' => 'Source'],
    ['label' => '', 'actions' => true],
]);
?>
    <tbody>
    <?php foreach ($journals as $journal): ?>
        <tr>
            <td><?= View::text($journal['journal_no']) ?></td>
            <td><?= View::date($journal['posting_date']) ?></td>
            <td><?= View::text(SourceLabel::text(is_string($journal['source_type'] ?? null) ? $journal['source_type'] : '')) ?></td>
            <td><a href="/<?= View::text($slug) ?>/journals/<?= View::text($journal['id']) ?>">Open</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php ListTable::assets(); ?>
