<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var array{id: string, name: string, kind: string, roles: list<string>} $party */
/** @var string $rolesLabel */
/** @var string $emptyRolesHint */

$name = $party['name'] !== '' ? $party['name'] : 'Party';
$roles = $rolesLabel !== '' ? $rolesLabel : 'none';
?>
<div class="page-head">
    <div class="titles">
        <nav class="breadcrumb">
            <a href="/<?= View::text($slug) ?>/parties">Parties</a><span class="sep">/</span>
            <span><?= View::text($name) ?></span>
        </nav>
        <h1 class="h1"><?= View::text($name) ?></h1>
        <p class="muted"><?= View::text($emptyRolesHint) ?></p>
    </div>
</div>
<div class="card mt-6">
    <div class="card-body stack">
        <p>Kind: <?= View::text($party['kind']) ?></p>
        <p>Roles: <?= View::text($roles) ?></p>
    </div>
</div>
