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
$mark = 'P';
$links = [
    ['/admin/tenants', 'Tenants', 'i-dashboard'],
    ['/admin/audit', 'Audit', 'i-report'],
    ['/admin/password', 'Password', 'i-lock'],
];
?>
<!DOCTYPE html>
<html lang="en" data-theme="neutral" data-mode="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex">
    <title><?= View::text($title) ?> · Platform</title>
    <link rel="stylesheet" href="/assets/inter.css">
    <link rel="stylesheet" href="/assets/theme.css">
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="stylesheet" href="/assets/mall.css">
    <script>
        (function () {
            try {
                var theme = localStorage.getItem('pc-theme') || localStorage.getItem('nem-theme');
                var mode = localStorage.getItem('pc-mode') || localStorage.getItem('nem-mode');
                var root = document.documentElement;
                if (theme) {
                    root.setAttribute('data-theme', theme);
                    localStorage.setItem('pc-theme', theme);
                }
                if (mode) {
                    root.setAttribute('data-mode', mode);
                    localStorage.setItem('pc-mode', mode);
                }
            } catch (e) {}
        })();
    </script>
</head>
<body>
<a class="skip" href="#main">Skip to main</a>
<?php require $templateDirectory . '/Shared/Web/icons.php'; ?>
<?php if ($nav): ?>
<div class="app" id="app" data-collapsed="false" data-mobile-open="false">
    <aside class="sidebar" id="sidebar" aria-label="Primary">
        <div class="sidebar-brand">
            <span class="brand-mark" aria-hidden="true"><?= View::text($mark) ?></span>
            <span class="brand-text">
                <span class="name">Platform</span>
                <span class="sub">Perfect Consign</span>
            </span>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-group-label">Operator</div>
            <?php foreach ($links as $link): ?>
                <?php
                $href = $link[0];
                $label = $link[1];
                $icon = $link[2];
                $active = $path === $href
                    || ($href === '/admin/tenants' && ($path === '/admin' || str_starts_with($path, '/admin/tenants')))
                    || ($href !== '/admin/tenants' && str_starts_with($path, $href));
                ?>
                <a class="nav-item" href="<?= View::text($href) ?>"<?= $active ? ' aria-current="page"' : '' ?> title="<?= View::text($label) ?>">
                    <svg aria-hidden="true"><use href="#<?= View::text($icon) ?>"></use></svg>
                    <span class="nav-label"><?= View::text($label) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-foot">
            <button class="collapse-btn" type="button" data-collapse-toggle aria-label="Collapse sidebar">
                <svg aria-hidden="true"><use href="#i-chevron"></use></svg>
                <span>Collapse</span>
            </button>
        </div>
    </aside>
    <div class="main">
        <header class="topbar">
            <button class="icon-btn mobile-only" type="button" data-mobile-toggle aria-label="Open navigation">
                <svg aria-hidden="true"><use href="#i-menu"></use></svg>
            </button>
            <div class="topbar-spacer"></div>
            <div class="topbar-actions">
                <div class="account" data-menu>
                    <button class="account-btn" type="button" data-menu-trigger aria-haspopup="true" aria-expanded="false" aria-label="Platform admin">
                        <span class="avatar" aria-hidden="true"><?= View::text($mark) ?></span>
                        <span class="account-meta">
                            <span class="name"><?= View::text($adminEmail) ?></span>
                            <span class="role">Platform admin</span>
                        </span>
                        <svg style="width:14px;height:14px;color:var(--text-subtle)" aria-hidden="true"><use href="#i-chevron-down"></use></svg>
                    </button>
                    <div class="menu" data-menu-panel hidden>
                        <a class="menu-item" href="/admin/password">
                            <svg aria-hidden="true"><use href="#i-lock"></use></svg>
                            Password
                        </a>
                        <div class="menu-sep" role="separator"></div>
                        <form method="post" action="/admin/logout">
                            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                            <button class="menu-item" type="submit">
                                <svg aria-hidden="true"><use href="#i-logout"></use></svg>
                                Sign out
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </header>
        <main class="content" id="main">
<?php else: ?>
<main id="main">
<?php endif; ?>
    <?php require $templateDirectory . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $body) . '.php'; ?>
</main>
<?php if ($nav): ?>
    </div>
    <div class="sidebar-scrim" data-mobile-toggle></div>
</div>
<script src="/assets/js/app.js" defer></script>
<?php endif; ?>
<?php require $templateDirectory . '/Shared/Web/flash.php'; ?>
</body>
</html>
