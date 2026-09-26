<?php

declare(strict_types=1);

use PerfectConsign\Http\View;

/** @var string $slug */
/** @var string $csrf */
?>
<h1>Sign in</h1>
<form method="post" action="/<?= View::text($slug) ?>/login">
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
    <label for="username">Username</label>
    <input id="username" name="username" autocomplete="username" required>
    <label for="password">Password</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required>
    <p><button type="submit">Sign in</button></p>
</form>
