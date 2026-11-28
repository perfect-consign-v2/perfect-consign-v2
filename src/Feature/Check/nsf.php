<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var ?string $error */
/** @var string $number */
/** @var string $today */
/** @var list<array{journal_id: string, check_number: string, received_on: string, amount_minor: int, said: string, bounced: bool}> $found */
?>
<h1>Bounced check</h1>
<p>When the bank sends back a check the store took, find it by its number and mark it bounced. The store's checks go down by the check's amount and Returned checks go up by the same amount.</p>
<?php if ($error !== null && $error !== ''): ?>
    <p class="error"><?= View::text($error) ?></p>
<?php endif; ?>
<form method="get" action="/<?= View::text($slug) ?>/checks/nsf">
    <label for="number">Check number</label>
    <input id="number" name="number" required maxlength="40" value="<?= View::text($number) ?>">
    <button type="submit">Find the check</button>
</form>
<?php if ($number !== '' && $found === []): ?>
    <p>No check numbered <?= View::text($number) ?> was taken. Check the number on the bank's notice.</p>
<?php endif; ?>
<?php foreach ($found as $check): ?>
    <div class="card">
        <div class="card-body stack">
            <p><strong>Check <?= View::text($check['check_number']) ?></strong> for <?= View::money($check['amount_minor']) ?>, taken <?= View::date($check['received_on']) ?> · <?= View::text($check['said']) ?></p>
            <?php if ($check['bounced']): ?>
                <p class="muted">This check is already marked as bounced.</p>
            <?php else: ?>
                <form class="stack" method="post" action="/<?= View::text($slug) ?>/checks/nsf">
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <input type="hidden" name="journal_id" value="<?= View::text($check['journal_id']) ?>">
                    <input type="hidden" name="number" value="<?= View::text($number) ?>">
                    <label for="posting_date-<?= View::text($check['journal_id']) ?>">Day the bank sent it back</label>
                    <input id="posting_date-<?= View::text($check['journal_id']) ?>" name="posting_date" type="date" required value="<?= View::text($today) ?>">
                    <button type="submit">It bounced</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
