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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::text($title) ?></title>
    <style>
        body { margin: 0; font: 16px/1.4 system-ui, sans-serif; background: #f6f4ef; color: #1c1915; }
        header, main { padding: 1rem; }
        header { background: #1c1915; color: #f6f4ef; }
        a { color: inherit; }
        nav { display: flex; flex-wrap: wrap; gap: 0.75rem; margin-top: 0.75rem; }
        nav a { color: #f6f4ef; }
        h1 { font-size: 1.4rem; margin: 0 0 1rem; }
        form, table { width: 100%; }
        label { display: block; margin: 0.75rem 0 0.25rem; }
        input, select, button { font: inherit; padding: 0.5rem; width: 100%; box-sizing: border-box; }
        button, .button { background: #1c1915; color: #f6f4ef; border: 0; }
        table { border-collapse: collapse; background: #fff; }
        th, td { text-align: left; padding: 0.4rem; border-bottom: 1px solid #ddd; vertical-align: top; }
        .error { background: #fff; border-left: 4px solid #8a1f1f; padding: 0.75rem; margin-bottom: 1rem; }
        .card { background: #fff; padding: 1rem; margin-bottom: 1rem; }
        @media (min-width: 40rem) {
            header, main { padding: 1.5rem 2rem; }
            input, select, button { width: auto; }
        }
    </style>
</head>
<body>
<header>
    <strong><?= View::text($displayName !== '' ? $displayName : 'Perfect Consign') ?></strong>
    <?php if ($nav): ?>
        <nav>
            <a href="/<?= View::text($slug) ?>/book">Book</a>
            <a href="/<?= View::text($slug) ?>/journals">Journals</a>
            <a href="/<?= View::text($slug) ?>/adjustment">Adjustment</a>
            <a href="/<?= View::text($slug) ?>/parties">Vendors</a>
            <a href="/<?= View::text($slug) ?>/rent">Rent</a>
            <a href="/<?= View::text($slug) ?>/payout">Payout</a>
            <a href="/<?= View::text($slug) ?>/sale">Sale</a>
            <a href="/<?= View::text($slug) ?>/tax">Tax</a>
            <a href="/<?= View::text($slug) ?>/opening">Opening</a>
            <a href="/<?= View::text($slug) ?>/close">Close</a>
            <a href="/<?= View::text($slug) ?>/export">Export</a>
            <form method="post" action="/<?= View::text($slug) ?>/logout">
                <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                <button type="submit">Sign out</button>
            </form>
        </nav>
    <?php endif; ?>
</header>
<main>
    <?php if ($error !== null && $error !== ''): ?>
        <p class="error"><?= View::text($error) ?></p>
    <?php endif; ?>
    <?php require $templateDirectory . DIRECTORY_SEPARATOR . $body . '.php'; ?>
</main>
</body>
</html>
