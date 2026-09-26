<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var string $backupHeadline */
?>
<h1>Sign out</h1>
<div class="card">
    <h2>Last backup you downloaded</h2>
    <p><?= View::text($backupHeadline) ?></p>
    <form method="post" action="/<?= View::text($slug) ?>/backup">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <input type="hidden" name="return" value="sign-out">
        <p><button type="submit">Download backup</button></p>
    </form>
    <form method="post" action="/<?= View::text($slug) ?>/logout/confirm">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <p><button type="submit">Sign out</button></p>
    </form>
</div>
