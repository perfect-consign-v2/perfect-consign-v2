<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var string $token */
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Open your account</h1>
        <p class="muted">This page does not sign you in until you continue.</p>
    </div>
</div>
<form class="stack" method="post" action="/<?= View::text($slug) ?>/consignor/enter/<?= View::text($token) ?>">
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
    <button class="btn btn-primary" type="submit">Open my account</button>
</form>
