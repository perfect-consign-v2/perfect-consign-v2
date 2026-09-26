<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var list<array<string, mixed>> $accounts */
/** @var string $templateDirectory */
?>
<h1>Adjustment</h1>
<form method="post" action="/<?= View::text($slug) ?>/adjustment">
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
    <label for="source_reference">Source reference</label>
    <input id="source_reference" name="source_reference" required>
    <label for="posting_date">Posting date</label>
    <input id="posting_date" name="posting_date" type="date" required>
    <label for="description">Description</label>
    <input id="description" name="description" required>
    <?php
    $subledger = false;
    for ($slot = 1; $slot <= 4; $slot++) {
        require $templateDirectory . '/Shared/Web/partials/line.php';
    }
    ?>
    <p><button type="submit">Post adjustment</button></p>
</form>
