<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var bool $allowed */
/** @var list<array<string, mixed>> $accounts */
/** @var string $templateDirectory */
/** @var string $today */
?>
<h1>Starting balances</h1>
<?php if (!$allowed): ?>
    <p>An opening balance is hidden once any other journal exists.</p>
<?php else: ?>
    <form method="post" action="/<?= View::text($slug) ?>/opening">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <label for="posting_date">Posting date</label>
        <input id="posting_date" name="posting_date" type="date" value="<?= View::text($today) ?>" required>
        <?php
        $subledger = true;
        for ($slot = 1; $slot <= 6; $slot++) {
            require $templateDirectory . '/Shared/Web/partials/line.php';
        }
        ?>
        <p><button type="submit">Post opening</button></p>
    </form>
<?php endif; ?>
