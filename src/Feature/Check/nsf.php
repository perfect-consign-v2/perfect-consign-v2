<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var ?string $error */
?>
<h1>Bounced check</h1>
<?php if ($error !== null && $error !== ''): ?>
    <p><?= View::text($error) ?></p>
<?php endif; ?>
<p>This credits checks and debits returned checks. It is a dated reversal of that tender.</p>
<form method="post" action="/<?= View::text($slug) ?>/checks/nsf">
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
    <label for="source_reference">Check reference</label>
    <input id="source_reference" name="source_reference" required>
    <label for="amount">Amount</label>
    <input id="amount" name="amount" inputmode="decimal" required>
    <label for="posting_date">Day</label>
    <input id="posting_date" name="posting_date" type="date" required>
    <button type="submit">Post</button>
</form>
