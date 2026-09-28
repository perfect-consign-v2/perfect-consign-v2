<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $title */
/** @var string $displayName */
/** @var string $slug */
/** @var bool $nav */
/** @var string $csrf */
/** @var string|null $error */
/** @var string|null $notice */
/** @var string $body */
/** @var string $templateDirectory */
/** @var string $path */

$path = $path ?? '';
$notice = $notice ?? null;
$mark = $displayName !== '' ? strtoupper(substr($displayName, 0, 1)) : 'P';
$groups = [
    'Overview' => [
        ['/book', 'Book', 'i-report'],
        ['/journals', 'Journals', 'i-receipt'],
    ],
    'Operations' => [
        ['/sale', 'Sale', 'i-pos'],
        ['/rent', 'Rent', 'i-booth'],
        ['/payout', 'Payout', 'i-wallet'],
        ['/parties', 'Vendors', 'i-vendor'],
        ['/booths', 'Booths', 'i-booth'],
        ['/map', 'Booths Map', 'i-map'],
        ['/brands', 'Brands', 'i-tag'],
    ],
    'Business' => [
        ['/adjustment', 'Adjustment', 'i-edit'],
        ['/tax', 'Tax', 'i-percent'],
        ['/opening', 'Opening', 'i-plus'],
        ['/close', 'Close', 'i-lock'],
        ['/export', 'Export', 'i-arrow-down'],
        ['/settings', 'Settings', 'i-settings'],
    ],
];
?>
<!DOCTYPE html>
<html lang="en" data-theme="neutral" data-mode="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title><?= View::text($title) ?><?= $displayName !== '' ? ' · ' . View::text($displayName) : '' ?></title>
    <link rel="preconnect" href="https://rsms.me/">
    <link rel="stylesheet" href="https://rsms.me/inter/inter.css">
    <link rel="stylesheet" href="/assets/theme.css">
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="stylesheet" href="/assets/mall.css">
    <script>
        (function () {
            try {
                var theme = localStorage.getItem('nem-theme');
                var mode = localStorage.getItem('nem-mode');
                var root = document.documentElement;
                if (theme) root.setAttribute('data-theme', theme);
                if (mode) root.setAttribute('data-mode', mode);
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
                <span class="name"><?= View::text($displayName) ?></span>
                <span class="sub">Perfect Consign</span>
            </span>
        </div>
        <nav class="sidebar-nav">
            <?php foreach ($groups as $group => $links): ?>
                <div class="nav-group-label"><?= View::text($group) ?></div>
                <?php foreach ($links as $link): ?>
                    <?php
                    $href = '/' . $slug . $link[0];
                    $active = $path === $href || str_starts_with($path, $href . '/');
                    ?>
                    <a class="nav-item" href="<?= View::text($href) ?>"<?= $active ? ' aria-current="page"' : '' ?> title="<?= View::text($link[1]) ?>">
                        <svg aria-hidden="true"><use href="#<?= View::text($link[2]) ?>"></use></svg>
                        <span class="nav-label"><?= View::text($link[1]) ?></span>
                    </a>
                <?php endforeach; ?>
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
            <form class="topbar-search" role="search" action="<?= View::text($path) ?>" method="get">
                <svg aria-hidden="true"><use href="#i-search"></use></svg>
                <input type="search" name="q" placeholder="Search items, vendors, booths…" aria-label="Search" autocomplete="off" data-global-search>
                <kbd>/</kbd>
            </form>
            <div class="topbar-spacer"></div>
            <div class="topbar-actions">
                <button class="icon-btn" type="button" aria-label="Notifications">
                    <svg aria-hidden="true"><use href="#i-bell"></use></svg>
                </button>
                <div class="account" data-menu>
                    <button class="icon-btn" type="button" data-menu-trigger aria-haspopup="true" aria-expanded="false" aria-label="Appearance">
                        <svg aria-hidden="true"><use href="#i-palette"></use></svg>
                    </button>
                    <div class="menu" data-menu-panel hidden>
                        <div class="menu-label">Theme</div>
                        <button class="menu-item" type="button" role="menuitemradio" data-set-theme="neutral" aria-checked="true">
                            <span class="swatch" style="background:#4f46e5"></span>
                            Neutral
                            <svg style="margin-left:auto" aria-hidden="true"><use href="#i-check"></use></svg>
                        </button>
                        <button class="menu-item" type="button" role="menuitemradio" data-set-theme="dark-blue" aria-checked="false">
                            <span class="swatch" style="background:#1e3a8a"></span>
                            Dark blue
                            <svg style="margin-left:auto" aria-hidden="true"><use href="#i-check"></use></svg>
                        </button>
                        <div class="menu-sep"></div>
                        <div class="menu-label">Mode</div>
                        <button class="menu-item" type="button" role="menuitemradio" data-set-mode="light" aria-checked="true">
                            <svg aria-hidden="true"><use href="#i-sun"></use></svg>
                            Light
                            <svg style="margin-left:auto" aria-hidden="true"><use href="#i-check"></use></svg>
                        </button>
                        <button class="menu-item" type="button" role="menuitemradio" data-set-mode="dark" aria-checked="false">
                            <svg aria-hidden="true"><use href="#i-moon"></use></svg>
                            Dark
                            <svg style="margin-left:auto" aria-hidden="true"><use href="#i-check"></use></svg>
                        </button>
                    </div>
                </div>
                <div class="account" data-menu>
                    <button class="account-btn" type="button" data-menu-trigger aria-haspopup="true" aria-expanded="false">
                        <span class="avatar" aria-hidden="true"><?= View::text($mark) ?></span>
                        <span class="account-meta">
                            <span class="name"><?= View::text($displayName) ?></span>
                            <span class="role">Owner</span>
                        </span>
                        <svg style="width:14px;height:14px;color:var(--text-subtle)" aria-hidden="true"><use href="#i-chevron-down"></use></svg>
                    </button>
                    <div class="menu" data-menu-panel hidden>
                        <form method="post" action="/<?= View::text($slug) ?>/logout">
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
    <?php if ($notice !== null && $notice !== ''): ?>
        <div class="alert alert-success" role="status">
            <svg style="width:18px;height:18px;flex-shrink:0" aria-hidden="true"><use href="#i-check"></use></svg>
            <p class="error"><?= View::text($notice) ?></p>
        </div>
    <?php endif; ?>
    <?php if ($error !== null && $error !== ''): ?>
        <div class="alert alert-danger" role="status">
            <svg style="width:18px;height:18px;flex-shrink:0" aria-hidden="true"><use href="#i-alert"></use></svg>
            <p class="error"><?= View::text($error) ?></p>
        </div>
    <?php endif; ?>
    <?php require $templateDirectory . DIRECTORY_SEPARATOR . $body . '.php'; ?>
</main>
<?php if ($nav): ?>
    </div>
    <div class="sidebar-scrim" data-mobile-toggle></div>
</div>
<script src="/assets/js/app.js" defer></script>
<?php endif; ?>
</body>
</html>
