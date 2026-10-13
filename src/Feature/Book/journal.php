<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var array<string, mixed> $journal */
/** @var list<array<string, mixed>> $lines */
/** @var bool $canVoid */
?>
<h1>Posted entry <?= View::text($journal['journal_no']) ?></h1>
<p>Key <?= View::text($journal['posting_key']) ?></p>
<p>Source <?= View::text($journal['source_type']) ?> <?= View::text($journal['source_reference']) ?></p>
<p>Date <?= View::date($journal['posting_date']) ?></p>
<p><?= View::text($journal['description']) ?></p>
<?php
$sourceType = is_string($journal['source_type'] ?? null) ? $journal['source_type'] : '';
$issued = $sourceType === 'gift_certificate_issue'
    && !in_array($journal['is_reversal'] ?? null, [true, 1, '1', 't', 'true'], true);
?>
<?php if ($issued): ?>
    <p><a class="btn" href="/<?= View::text($slug) ?>/pos/gift/<?= View::text($journal['id']) ?>">Print certificate</a></p>
<?php endif; ?>
<table>
    <thead><tr><th>Line</th><th>Account</th><th>Debit</th><th>Credit</th><th>Subledger</th></tr></thead>
    <tbody>
    <?php foreach ($lines as $line): ?>
        <tr>
            <td><?= View::text($line['line_no']) ?></td>
            <td><?= View::text($line['code']) ?> <?= View::text($line['name']) ?></td>
            <td><?= View::text(View::money(View::minor($line['debit_minor']))) ?></td>
            <td><?= View::text(View::money(View::minor($line['credit_minor']))) ?></td>
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
