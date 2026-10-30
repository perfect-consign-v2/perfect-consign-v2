<?php

declare(strict_types=1);

use PerfectConsign\Feature\Vendor\VendorPreview;
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

$path = $path ?? '';
$robots = $robots ?? '';
$posShell = ($posShell ?? false) === true;
$activeRegister = $activeRegister ?? '';
$chooseRegister = $chooseRegister ?? false;
$leftOpen = is_array($leftOpen ?? null) ? $leftOpen : [];
$priorCloseStarted = ($priorCloseStarted ?? false) === true;
$priorPrompt = ($priorPrompt ?? false) === true;
$notice = $notice ?? null;
$warning = $warning ?? null;
$mark = $displayName !== '' ? strtoupper(substr($displayName, 0, 1)) : 'P';
$showBrand = ($showBrand ?? true) === true;
$boothRentals = ($boothRentals ?? true) === true;
$consignmentOnly = ($consignmentOnly ?? false) === true;
$partyLabel = $consignmentOnly ? 'Consignors' : 'Vendors';
$payLabel = $consignmentOnly ? 'Pay consignors' : 'Pay vendors';
$viewPayoutsLabel = 'View payouts';
$memoLabel = $consignmentOnly ? 'Consignor memo' : 'Vendor memo';
$portalLabel = $consignmentOnly ? 'Consignor' : 'Vendor';
$actorRole = is_string($actorRole ?? null) ? $actorRole : '';
$previewAs = is_string($previewAs ?? null) ? $previewAs : '';
$resetTestData = getenv('STALL_ALLOW_TENANT_RESET') === '1'
    && ($slug === 'dev' || $slug === 'mall')
    && $actorRole === 'owner';
$groups = [];
if ($actorRole === 'owner') {
    $groups = [
        'Counter' => [
            ['/dashboard', 'Home', 'i-dashboard'],
            ['/pos', 'Sell', 'i-cart'],
            ['/registers', 'Registers', 'i-pos'],
            ['/customers', 'Customers', 'i-vendor'],
        ],
        'Floor' => [
            ['/parties', $partyLabel, 'i-vendor'],
            ['/letters', 'Email', 'i-receipt'],
            ['/inventory', 'Inventory', 'i-inventory'],
            ['/booths', 'Booths', 'i-booth'],
            ['/map', 'Map', 'i-map'],
        ],
        'Money' => [
            ['/rent', 'Rent', 'i-booth'],
            ['/payouts', $payLabel, 'i-wallet'],
            ['/month', 'This month', 'i-report'],
        ],
        'Setup' => [
            ['/offers', 'Deals', 'i-percent'],
            ['/wait-list', 'Wait list', 'i-clock'],
        ],
    ];
    if ($showBrand) {
        $groups['Setup'][] = ['/brands', 'Brands', 'i-tag'];
    }
    $catalogNav = is_array($catalogNav ?? null) ? $catalogNav : [];
    foreach ($catalogNav as $link) {
        if (is_array($link)) {
            $groups['Setup'][] = $link;
        }
    }
    $groups['Setup'][] = ['/company', 'Company', 'i-settings'];
    $groups['Setup'][] = ['/settings', 'Settings', 'i-settings'];
    $groups['Setup'][] = ['/staff', 'People', 'i-vendor'];
    $groups['Books'] = [
        ['/adjustment', 'Fix a mistake', 'i-edit'],
        ['/missed-sale', 'Missed sale', 'i-edit'],
        ['/store-use', 'Buy for the store', 'i-edit'],
        ['/vendor-memo', $memoLabel, 'i-edit'],
        ['/tax', 'Sales tax', 'i-percent'],
        ['/book', 'Accounts', 'i-report'],
        ['/gift-certificates', 'Gift certificates', 'i-receipt'],
        ['/journals', 'Money record', 'i-receipt'],
        ['/reports', 'Reports', 'i-report'],
        ['/floor', 'Floor', 'i-booth'],
        ['/mall-sale', 'Mall sale', 'i-percent'],
        ['/booth-extras', 'Booth extras', 'i-booth'],
        ['/checks/nsf', 'Bounced check', 'i-edit'],
        ['/opening', 'Opening amounts', 'i-plus'],
        ['/close', 'Close the year', 'i-lock'],
        ['/export', 'Download records', 'i-arrow-down'],
    ];
}
if ($actorRole === 'manager') {
    $groups = [
        'Counter' => [
            ['/dashboard', 'Home', 'i-dashboard'],
            ['/pos', 'Sell', 'i-cart'],
            ['/registers', 'Register', 'i-pos'],
            ['/customers', 'Customers', 'i-vendor'],
        ],
        'Floor' => [
            ['/parties', $partyLabel, 'i-vendor'],
            ['/letters', 'Email', 'i-receipt'],
            ['/inventory', 'Inventory', 'i-inventory'],
            ['/booths', 'Booths', 'i-booth'],
            ['/map', 'Map', 'i-map'],
            ['/offers', 'Deals', 'i-percent'],
            ['/wait-list', 'Wait list', 'i-clock'],
        ],
        'Money' => [
            ['/rent', 'Rent', 'i-booth'],
            ['/month', 'This month', 'i-report'],
            ['/payouts', $viewPayoutsLabel, 'i-wallet'],
            ['/reports', 'Reports', 'i-report'],
        ],
    ];
}
if ($actorRole === 'books') {
    $groups = [
        'Books' => [
            ['/sales', 'Sales', 'i-report'],
            ['/month', 'This month', 'i-report'],
            ['/rent', 'Rent', 'i-booth'],
            ['/payouts', $viewPayoutsLabel, 'i-wallet'],
            ['/journals', 'Money record', 'i-receipt'],
            ['/reports', 'Reports', 'i-report'],
        ],
    ];
}
if ($actorRole === 'cashier') {
    $groups = [
        'Counter' => [
            ['/pos', 'Sell', 'i-cart'],
            ['/rent', 'Rent', 'i-booth'],
            ['/registers', 'Register', 'i-pos'],
        ],
    ];
}
if ($actorRole === 'vendor') {
    $groups = [
        $portalLabel => [
            ['/vendor', 'Check', 'i-wallet'],
            ['/vendor/sales', 'Sales', 'i-report'],
            ['/vendor/inventory', 'Inventory', 'i-inventory'],
            ['/vendor/offers', 'Deals', 'i-percent'],
        ],
    ];
}
if ($previewAs !== '') {
    $groups = [
        $portalLabel => [
            ['/vendor', 'Check', 'i-wallet'],
            ['/vendor/sales', 'Sales', 'i-report'],
            ['/vendor/inventory', 'Inventory', 'i-inventory'],
            ['/vendor/offers', 'Deals', 'i-percent'],
        ],
    ];
}
$hiddenNav = [];
if (!$boothRentals || $consignmentOnly) {
    $hiddenNav = ['/booths', '/rent', '/wait-list', '/map'];
}
if ($consignmentOnly) {
    $hiddenNav[] = '/mall-sale';
    $hiddenNav[] = '/booth-extras';
}
if ($hiddenNav !== []) {
    foreach ($groups as $name => $links) {
        $kept = [];
        foreach ($links as $link) {
            $navPath = is_string($link[0] ?? null) ? $link[0] : '';
            if (!in_array($navPath, $hiddenNav, true)) {
                $kept[] = $link;
            }
        }
        $groups[$name] = $kept;
    }
}
$roleLabel = match ($actorRole) {
    'owner' => 'Owner',
    'manager' => 'Manager',
    'cashier' => 'Cashier',
    'vendor' => $portalLabel,
    'books' => 'Books',
    default => '',
};
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark-blue" data-mode="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title><?= View::text($title) ?><?= $displayName !== '' ? ' · ' . View::text($displayName) : '' ?></title>
    <?php if ($robots === 'noindex'): ?>
        <meta name="robots" content="noindex">
    <?php endif; ?>
    <link rel="stylesheet" href="/assets/boot.css">
    <link rel="stylesheet" href="/assets/inter.css">
    <link rel="stylesheet" href="/assets/theme.css">
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="stylesheet" href="/assets/mall.css">
    <script src="/assets/js/theme-boot.js"></script>
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
                    <svg width="18" height="18" aria-hidden="true"><use href="#i-pos"></use></svg>
                    <span class="nav-label">Close the register</span>
                </a>
            <?php endif; ?>
            <?php if ($leftOpen === [] || !$priorCloseStarted): ?>
            <?php foreach ($groups as $group => $links): ?>
                <?php if ($links === []) { continue; } ?>
                <?php $foldBooks = $group === 'Books' && $actorRole === 'owner'; ?>
                <?php if ($foldBooks): ?><details class="nav-books"><?php endif; ?>
                <?php if ($foldBooks): ?>
                    <summary class="nav-group-label">Books</summary>
                <?php else: ?>
                    <div class="nav-group-label"><?= View::text($group) ?></div>
                <?php endif; ?>
                <?php foreach ($links as $link): ?>
                    <?php
                    $navPath = is_string($link[0] ?? null) ? $link[0] : '';
                    $navLabel = is_string($link[1] ?? null) ? $link[1] : '';
                    $navIcon = is_string($link[2] ?? null) ? $link[2] : '';
                    if ($navPath === '' || $navLabel === '' || $navIcon === '') {
                        continue;
                    }
                    $href = '/' . $slug . $navPath;
                    if ($previewAs !== '' && str_starts_with($navPath, '/vendor')) {
                        $href = VendorPreview::with($href, $previewAs);
                    }
                    $active = $navPath === '/vendor'
                        ? ($path === $href || str_starts_with($path, $href . '/checks/'))
                        : ($path === $href || str_starts_with($path, $href . '/'));
                    ?>
                    <a class="nav-item" href="<?= View::text($href) ?>"<?= $active ? ' aria-current="page"' : '' ?> title="<?= View::text($navLabel) ?>">
                        <svg width="18" height="18" aria-hidden="true"><use href="#<?= View::text($navIcon) ?>"></use></svg>
                        <span class="nav-label"><?= View::text($navLabel) ?></span>
                    </a>
                <?php endforeach; ?>
                <?php if ($foldBooks): ?></details><?php endif; ?>
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
            <div class="topbar-spacer"></div>
            <?php if ($previewAs === '' && $resetTestData): ?>
                <form method="post" action="/<?= View::text($slug) ?>/reset-test-data">
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <label for="reset-confirm">Type RESET</label>
                    <input class="input" id="reset-confirm" name="confirm" autocomplete="off" required maxlength="5">
                    <button class="btn btn-danger" type="submit">Reset Test Data</button>
                </form>
            <?php endif; ?>
            <?php if ($previewAs === '' && $chooseRegister): ?>
                <a class="btn" href="/<?= View::text($slug) ?>/registers">Choose a register</a>
            <?php elseif ($previewAs === '' && $activeRegister !== ''): ?>
                <a class="btn" href="/<?= View::text($slug) ?>/registers">Active: <?= View::text($activeRegister) ?></a>
            <?php endif; ?>
            <div class="topbar-actions">
                <div class="account" data-menu>
                    <button class="icon-btn" type="button" data-menu-trigger aria-haspopup="menu" aria-expanded="false" aria-label="Appearance">
                        <svg width="18" height="18" aria-hidden="true"><use href="#i-palette"></use></svg>
                    </button>
                    <div class="menu" data-menu-panel role="menu" aria-label="Appearance" hidden>
                        <div role="group" aria-label="Theme">
                            <div class="menu-label" role="presentation">Theme</div>
                            <button class="menu-item" type="button" role="menuitemradio" data-set-theme="neutral" aria-checked="false">
                                <span class="swatch" data-style-prop="background:#4f46e5"></span>
                                Neutral
                                <svg class="menu-check" aria-hidden="true"><use href="#i-check"></use></svg>
                            </button>
                            <button class="menu-item" type="button" role="menuitemradio" data-set-theme="dark-blue" aria-checked="true">
                                <span class="swatch" data-style-prop="background:#1e3a8a"></span>
                                Dark blue
                                <svg class="menu-check" aria-hidden="true"><use href="#i-check"></use></svg>
                            </button>
                        </div>
                        <div class="menu-sep" role="separator"></div>
                        <div role="group" aria-label="Mode">
                            <div class="menu-label" role="presentation">Mode</div>
                            <button class="menu-item" type="button" role="menuitemradio" data-set-mode="light" aria-checked="true">
                                <svg aria-hidden="true"><use href="#i-sun"></use></svg>
                                Light
                                <svg class="menu-check" aria-hidden="true"><use href="#i-check"></use></svg>
                            </button>
                            <button class="menu-item" type="button" role="menuitemradio" data-set-mode="dark" aria-checked="false">
                                <svg aria-hidden="true"><use href="#i-moon"></use></svg>
                                Dark
                                <svg class="menu-check" aria-hidden="true"><use href="#i-check"></use></svg>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="account" data-menu>
                    <button class="account-btn" type="button" data-menu-trigger aria-haspopup="true" aria-expanded="false" aria-label="<?= View::text($displayName) ?>">
                        <span class="avatar" aria-hidden="true"><?= View::text($mark) ?></span>
                        <span class="account-meta">
                            <span class="name"><?= View::text($displayName) ?></span>
                            <span class="role"><?= View::text($roleLabel) ?></span>
                        </span>
                        <svg width="14" height="14" aria-hidden="true"><use href="#i-chevron-down"></use></svg>
                    </button>
                    <div class="menu" data-menu-panel hidden>
                        <a class="menu-item" href="/<?= View::text($slug) ?>/password">
                            <svg width="16" height="16" aria-hidden="true"><use href="#i-lock"></use></svg>
                            Change password
                        </a>
                        <div class="menu-sep" role="separator"></div>
                        <form method="post" action="/<?= View::text($slug) ?>/logout">
                            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                            <button class="menu-item" type="submit">
                                <svg width="16" height="16" aria-hidden="true"><use href="#i-logout"></use></svg>
                                Sign out
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </header>
        <main class="content" id="main">
            <?php if ($previewAs !== ''): ?>
                <p class="muted">You are signed in as the owner, looking at this <?= $consignmentOnly ? 'consignor' : 'vendor' ?>'s portal. <a href="/<?= View::text($slug) ?>/vendors/<?= View::text($previewAs) ?>">Back to their record</a></p>
            <?php endif; ?>
<?php else: ?>
<main id="main">
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
<script src="/assets/js/prior-register.js"></script>
<?php endif; ?>
<?php require $templateDirectory . '/Shared/Web/flash.php'; ?>
<script src="/assets/js/data-style.js" defer></script>
<script src="/assets/js/password-toggle.js" defer></script>
<script src="/assets/js/form-auto-submit.js" defer></script>
<script src="/assets/js/register-swap.js"></script>
</body>
</html>
