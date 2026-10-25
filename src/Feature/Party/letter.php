<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var array{subject: string, body: string, filed_on: string, copies: list<array{name: string, email: string, status: string}>} $letter */
?>
<div class="page-head">
    <div class="titles">
        <nav class="breadcrumb">
            <a href="/<?= View::text($slug) ?>/letters">Email</a><span class="sep">/</span>
            <span><?= View::text($letter['subject']) ?></span>
        </nav>
        <h1 class="h1"><?= View::text($letter['subject']) ?></h1>
        <p class="muted">Filed <?= View::date($letter['filed_on']) ?></p>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <p><?= nl2br(View::text($letter['body']), false) ?></p>
    </div>
</div>

<div class="card mt-6">
    <div class="card-head"><div class="h3">Recipients</div></div>
    <div class="card-body">
        <ul class="stack">
            <?php foreach ($letter['copies'] as $copy): ?>
                <li>
                    <span class="strong"><?= View::text($copy['name']) ?></span>
                    <span class="muted"><?= View::text($copy['email']) ?></span>
                    <span class="badge"><?= View::text($copy['status']) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
