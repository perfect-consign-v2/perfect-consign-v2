<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $displayName */
/** @var string $slug */
?>
<h1><?= View::text($displayName) ?></h1>
<p><a class="button" href="/<?= View::text($slug) ?>/login">Sign in</a></p>
