<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $displayName */
/** @var list<string> $companyLines */
/** @var string $kicker */
$companyLines = is_array($companyLines ?? null) ? $companyLines : [];
?>
<header class="slip-center">
    <p class="slip-store"><?= View::text($displayName) ?></p>
    <?php foreach ($companyLines as $line): ?>
        <?php if (is_string($line) && $line !== ''): ?>
            <p class="slip-meta"><?= View::text($line) ?></p>
        <?php endif; ?>
    <?php endforeach; ?>
    <p class="slip-kicker"><?= View::text($kicker) ?></p>
</header>
