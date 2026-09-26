<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $title */
/** @var string $displayName */
/** @var string $slug */
/** @var bool $nav */
/** @var string $csrf */
/** @var string|null $error */
/** @var string $body */
/** @var string $templateDirectory */
/** @var string $path */

$path = $path ?? '';
$groups = [
    'Overview' => [
        '/book' => 'Book',
        '/journals' => 'Journals',
    ],
    'Operations' => [
        '/sale' => 'Sale',
        '/rent' => 'Rent',
        '/payout' => 'Payout',
        '/parties' => 'Vendors',
        '/map' => 'Booth map',
    ],
    'Business' => [
        '/adjustment' => 'Adjustment',
        '/tax' => 'Tax',
        '/opening' => 'Opening',
        '/close' => 'Close',
        '/export' => 'Export',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::text($title) ?></title>
    <link rel="stylesheet" href="/assets/tokens.css">
</head>
<body>
<a class="skip" href="#main">Skip to main</a>
<?php if ($nav): ?>
<input class="nav-toggle" id="nav-open" type="checkbox">
<div class="shell">
    <nav class="sidebar" aria-label="Mall">
        <?php foreach ($groups as $group => $links): ?>
            <h2><?= View::text($group) ?></h2>
            <?php foreach ($links as $suffix => $label): ?>
                <?php $href = '/' . $slug . $suffix; ?>
                <a href="<?= View::text($href) ?>"<?= $path === $href ? ' aria-current="page"' : '' ?>><?= View::text($label) ?></a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>
    <div class="workspace">
        <header class="topbar">
            <label class="menu" for="nav-open">Menu</label>
            <strong><?= View::text($displayName) ?></strong>
            <form method="post" action="/<?= View::text($slug) ?>/logout">
                <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                <button type="submit">Sign out</button>
            </form>
        </header>
        <main id="main">
<?php else: ?>
<main id="main" class="solo">
    <?php if ($displayName !== ''): ?>
        <p><?= View::text($displayName) ?></p>
    <?php endif; ?>
<?php endif; ?>
    <?php if ($error !== null && $error !== ''): ?>
        <p class="error"><?= View::text($error) ?></p>
    <?php endif; ?>
    <?php require $templateDirectory . DIRECTORY_SEPARATOR . $body . '.php'; ?>
</main>
<?php if ($nav): ?>
    </div>
</div>
<?php endif; ?>
</body>
</html>
