<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $displayName */
/** @var string $homeSlug */
/** @var string $signInSlug */
/** @var string $back */

$homeSlug = $homeSlug ?? '';
$signInSlug = $signInSlug ?? '';
$back = $back ?? '';
$sentence = $displayName !== ''
    ? 'This address is not a page in ' . $displayName . '.'
    : 'This address is not in Perfect Consign.';
?>
<?php if ($homeSlug === ''): ?>
<div class="login-wrap">
    <div class="panel">
<?php endif; ?>
        <div class="card">
            <div class="card-head"><h1>Not found</h1></div>
            <div class="card-body grid" style="gap:var(--space-4)">
                <p><?= View::text($sentence) ?></p>
                <?php if ($homeSlug !== '' || $signInSlug !== '' || $back !== ''): ?>
                <div class="row gap-3">
                    <?php if ($homeSlug !== ''): ?>
                        <a class="btn btn-primary" href="/<?= View::text($homeSlug) ?>/dashboard">Go to the dashboard</a>
                    <?php elseif ($signInSlug !== ''): ?>
                        <a class="btn btn-primary" href="/<?= View::text($signInSlug) ?>/login">Sign in</a>
                    <?php endif; ?>
                    <?php if ($back !== ''): ?>
                        <a class="btn" href="<?= View::text($back) ?>">Back</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
<?php if ($homeSlug === ''): ?>
    </div>
</div>
<?php endif; ?>
