<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var array<string, mixed> $journal */
/** @var list<array<string, mixed>> $lines */
/** @var bool $canVoid */
?>
<h1>Journal <?= View::text($journal['journal_no']) ?></h1>
<p>Key <?= View::text($journal['posting_key']) ?></p>
<p>Source <?= View::text($journal['source_type']) ?> <?= View::text($journal['source_reference']) ?></p>
<p>Date <?= View::text($journal['posting_date']) ?></p>
<p><?= View::text($journal['description']) ?></p>
<table>
    <thead><tr><th>Line</th><th>Account</th><th>Debit</th><th>Credit</th><th>Subledger</th></tr></thead>
    <tbody>
    <?php foreach ($lines as $line): ?>
        <tr>
            <td><?= View::text($line['line_no']) ?></td>
            <td><?= View::text($line['code']) ?> <?= View::text($line['name']) ?></td>
            <td><?= View::text(View::money((int) $line['debit_minor'])) ?></td>
            <td><?= View::text(View::money((int) $line['credit_minor'])) ?></td>
            <td><?= View::text($line['subledger_type']) ?> <?= View::text($line['subledger_ref']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php if ($canVoid): ?>
    <form method="post" action="/<?= View::text($slug) ?>/journals/<?= View::text($journal['id']) ?>/void">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <label for="posting_date">Void posting date</label>
        <input id="posting_date" name="posting_date" type="date" required min="<?= View::text($journal['posting_date']) ?>" value="<?= View::text($journal['posting_date']) ?>">
        <p><button type="submit">Void</button></p>
    </form>
<?php endif; ?>
