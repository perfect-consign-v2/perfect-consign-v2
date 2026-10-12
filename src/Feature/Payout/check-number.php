<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var string $displayName */
/** @var string $runId */
/** @var string|null $error */
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Print checks</h1>
        <p class="muted"><?= View::text($displayName) ?></p>
    </div>
</div>
<?php if (is_string($error) && $error !== ''): ?>
    <p class="alert"><?= View::text($error) ?></p>
<?php endif; ?>
<form class="stack" method="post" action="/<?= View::text($slug) ?>/payouts/checks?run=<?= View::text($runId) ?>">
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
    <label for="starting_check">First check number</label>
    <input class="input" id="starting_check" name="starting_check" inputmode="numeric">
    <p class="muted">The number on the first check. The next checks count up from here.</p>
    <button class="btn btn-primary" type="submit">Print checks</button>
</form>
