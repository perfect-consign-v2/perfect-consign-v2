<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $title */
/** @var string $csrf */
/** @var string|null $error */
/** @var string|null $notice */
/** @var string|null $warning */
/** @var string $body */
/** @var string $templateDirectory */
/** @var bool $nav */
/** @var string $adminEmail */
/** @var string $path */

$nav = ($nav ?? false) === true;
$adminEmail = is_string($adminEmail ?? null) ? $adminEmail : '';
$error = $error ?? null;
$notice = $notice ?? null;
$warning = $warning ?? null;
$path = $path ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::text($title) ?> · Platform</title>
    <link rel="stylesheet" href="/assets/mall.css">
</head>
<body>
<?php if ($nav): ?>
<header class="topbar">
    <div class="row between" style="align-items:center; gap:var(--space-4); padding:var(--space-3) var(--space-4)">
        <div class="row gap-3" style="align-items:center">
            <span class="brand-mark" style="width:32px;height:32px;font-size:var(--text-sm)">P</span>
            <div>
                <div class="h3" style="margin:0">Platform admin</div>
                <div class="text-sm muted"><?= View::text($adminEmail) ?></div>
            </div>
        </div>
        <nav class="row gap-3" style="align-items:center">
            <a href="/admin/tenants" class="<?= str_starts_with($path, '/admin/tenants') || $path === '/admin' ? 'active' : '' ?>">Tenants</a>
            <a href="/admin/audit" class="<?= str_starts_with($path, '/admin/audit') ? 'active' : '' ?>">Audit</a>
            <a href="/admin/password" class="<?= str_starts_with($path, '/admin/password') ? 'active' : '' ?>">Password</a>
            <form method="post" action="/admin/logout" style="margin:0">
                <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                <button class="btn btn-ghost" type="submit">Sign out</button>
            </form>
        </nav>
    </div>
</header>
<?php endif; ?>
<main class="<?= $nav ? 'page' : '' ?>">
    <?php if (is_string($notice) && $notice !== ''): ?>
        <div class="banner ok"><?= View::text($notice) ?></div>
    <?php endif; ?>
    <?php if (is_string($warning) && $warning !== ''): ?>
        <div class="banner warn"><?= View::text($warning) ?></div>
    <?php endif; ?>
    <?php if (is_string($error) && $error !== '' && $nav): ?>
        <div class="banner bad"><?= View::text($error) ?></div>
    <?php endif; ?>
    <?php require $templateDirectory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $body) . '.php'; ?>
</main>
</body>
</html>
