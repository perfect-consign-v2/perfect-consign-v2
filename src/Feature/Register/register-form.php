<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var string $registerId */
/** @var string $name */

$creating = $registerId === '';
$action = $creating ? '/' . $slug . '/registers' : '/' . $slug . '/registers/' . $registerId;
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1"><?= $creating ? 'New register' : 'Rename register' ?></h1>
    </div>
</div>
<form class="card stack" method="post" action="<?= View::text($action) ?>">
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
    <label for="name">Name</label>
    <input class="input" id="name" name="name" value="<?= View::text($name) ?>" maxlength="40" required>
    <div class="row gap-2">
        <button class="btn btn-primary" type="submit"><?= $creating ? 'Create register' : 'Rename register' ?></button>
        <a class="btn" href="/<?= View::text($slug) ?>/registers">Cancel</a>
    </div>
</form>
