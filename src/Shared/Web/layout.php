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
/** @var string|null $warning */
/** @var string $body */
/** @var string $templateDirectory */
/** @var string $path */
/** @var string $robots */
/** @var bool $showSearch */

$path = $path ?? '';
$robots = $robots ?? '';
$showSearch = ($showSearch ?? true) === true;
$posShell = ($posShell ?? false) === true;
$activeRegister = $activeRegister ?? '';
$chooseRegister = $chooseRegister ?? false;
$leftOpen = is_array($leftOpen ?? null) ? $leftOpen : [];
$priorCloseStarted = ($priorCloseStarted ?? false) === true;
$priorPrompt = ($priorPrompt ?? false) === true;
$notice = $notice ?? null;
$warning = $warning ?? null;
$mark = $displayName !== '' ? strtoupper(substr($displayName, 0, 1)) : 'P';
$groups = [
    'Overview' => [
        ['/dashboard', 'Dashboard', 'i-dashboard'],
    ],
    'Operations' => [
        ['/pos', 'POS', 'i-cart'],
        ['/parties', 'Vendors', 'i-vendor'],
        ['/inventory', 'Inventory', 'i-inventory'],
        ['/booths', 'Booths', 'i-booth'],
        ['/registers', 'Registers', 'i-pos'],
        ['/rent', 'Rent', 'i-booth'],
        ['/payout', 'Payout', 'i-wallet'],
        ['/wait-list', 'Wait list', 'i-clock'],
        ['/map', 'Booths Map', 'i-map'],
        ['/brands', 'Brands', 'i-tag'],
    ],
    'Business' => [
        ['/adjustment', 'Adjustment', 'i-edit'],
        ['/tax', 'Tax', 'i-percent'],
        ['/book', 'Book', 'i-report'],
        ['/journals', 'Journals', 'i-receipt'],
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
    <?php if ($robots === 'noindex'): ?>
        <meta name="robots" content="noindex">
    <?php endif; ?>
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
<body<?= $posShell ? ' class="pos-register"' : '' ?>>
<a class="skip" href="#main">Skip to main</a>
<?php require $templateDirectory . '/Shared/Web/icons.php'; ?>
<?php if ($posShell && $nav): ?>
<div class="pos-app">
    <main class="pos-main" id="main">
<?php elseif ($nav): ?>
<div class="app" id="app" data-collapsed="false" data-mobile-open="false">
    <aside class="sidebar" id="sidebar" aria-label="Primary"<?= $priorCloseStarted ? ' inert' : '' ?>>
        <div class="sidebar-brand">
            <span class="brand-mark" aria-hidden="true"><?= View::text($mark) ?></span>
            <span class="brand-text">
                <span class="name"><?= View::text($displayName) ?></span>
                <span class="sub">Perfect Consign</span>
            </span>
        </div>
        <nav class="sidebar-nav">
            <?php if ($leftOpen !== [] && $priorCloseStarted): ?>
                <div class="nav-group-label">Registers</div>
                <a class="nav-item" href="/<?= View::text($slug) ?>/registers" aria-current="page">
                    <svg aria-hidden="true"><use href="#i-pos"></use></svg>
                    <span class="nav-label">Close the register</span>
                </a>
            <?php endif; ?>
            <?php if ($leftOpen === [] || !$priorCloseStarted): ?>
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
            <?php endif; ?>
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
            <?php if ($showSearch): ?>
            <form class="topbar-search" role="search" action="<?= View::text($path) ?>" method="get"<?= $priorCloseStarted ? ' inert' : '' ?>>
                <svg aria-hidden="true"><use href="#i-search"></use></svg>
                <input type="search" name="q" placeholder="Search items, vendors, booths…" aria-label="Search" autocomplete="off" data-global-search>
                <kbd>/</kbd>
            </form>
            <?php endif; ?>
            <div class="topbar-spacer"></div>
            <?php if ($chooseRegister): ?>
                <a class="btn" href="/<?= View::text($slug) ?>/registers">Choose a register</a>
            <?php elseif ($activeRegister !== ''): ?>
                <a class="btn" href="/<?= View::text($slug) ?>/registers">Active: <?= View::text($activeRegister) ?></a>
            <?php endif; ?>
            <div class="topbar-actions">
                <button class="icon-btn" type="button" aria-label="Notifications">
                    <svg aria-hidden="true"><use href="#i-bell"></use></svg>
                </button>
                <div class="account" data-menu>
                    <button class="icon-btn" type="button" data-menu-trigger aria-haspopup="menu" aria-expanded="false" aria-label="Appearance">
                        <svg aria-hidden="true"><use href="#i-palette"></use></svg>
                    </button>
                    <div class="menu" data-menu-panel role="menu" aria-label="Appearance" hidden>
                        <div role="group" aria-label="Theme">
                            <div class="menu-label" role="presentation">Theme</div>
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
                        </div>
                        <div class="menu-sep" role="separator"></div>
                        <div role="group" aria-label="Mode">
                            <div class="menu-label" role="presentation">Mode</div>
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
                </div>
                <div class="account" data-menu>
                    <button class="account-btn" type="button" data-menu-trigger aria-haspopup="true" aria-expanded="false" aria-label="<?= View::text($displayName) ?>">
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
    <?php if ($body !== 'Feature/Pos/pos' && $notice !== null && $notice !== ''): ?>
        <div class="alert alert-success" role="status">
            <svg style="width:18px;height:18px;flex-shrink:0" aria-hidden="true"><use href="#i-check"></use></svg>
            <p class="notice"><?= View::text($notice) ?></p>
        </div>
    <?php endif; ?>
    <?php if ($warning !== null && $warning !== ''): ?>
        <div class="alert alert-warning" role="status">
            <svg style="width:18px;height:18px;flex-shrink:0" aria-hidden="true"><use href="#i-alert"></use></svg>
            <p class="warning"><?= View::text($warning) ?></p>
        </div>
    <?php endif; ?>
    <?php if ($body !== 'Feature/Pos/pos' && ($error !== null && $error !== '') && ($modal ?? '') === ''): ?>
        <div class="alert alert-danger" role="alert">
            <svg style="width:18px;height:18px;flex-shrink:0" aria-hidden="true"><use href="#i-alert"></use></svg>
            <p class="error"><?= View::text($error) ?></p>
        </div>
    <?php endif; ?>
    <?php require $templateDirectory . DIRECTORY_SEPARATOR . $body . '.php'; ?>
</main>
<?php if ($posShell && $nav): ?>
</div>
<?php elseif ($nav): ?>
    </div>
    <div class="sidebar-scrim" data-mobile-toggle></div>
</div>
<script src="/assets/js/app.js" defer></script>
<?php endif; ?>
<?php if ($nav && $priorPrompt): ?>
<dialog class="prior-register" id="prior-register" aria-labelledby="prior-register-title">
    <div class="prior-register-panel">
        <h2 class="h3" id="prior-register-title">Close the register from an earlier day</h2>
        <p>A register is still open from an earlier day. Close it before taking sales.</p>
        <ul>
            <?php foreach ($leftOpen as $stale): ?>
                <?php if (!is_array($stale)) {
                    continue;
                } ?>
                <li>
                    <strong><?= View::text(is_string($stale['name'] ?? null) ? $stale['name'] : 'Register') ?></strong>
                    was opened <?= View::text(is_string($stale['opened'] ?? null) ? $stale['opened'] : '') ?>.
                    <a class="text-link" href="/<?= View::text($slug) ?>/registers?close=<?= View::text(is_string($stale['id'] ?? null) ? $stale['id'] : '') ?>">Close this register</a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</dialog>
<script>
(function () {
    var dialog = document.getElementById('prior-register');
    if (!dialog || typeof dialog.showModal !== 'function') {
        return;
    }
    dialog.addEventListener('cancel', function (event) {
        event.preventDefault();
    });
    dialog.showModal();
})();
</script>
<?php endif; ?>
</body>
</html>
