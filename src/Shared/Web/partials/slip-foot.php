<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $receiptFooter */
$receiptFooter = is_string($receiptFooter ?? null) ? $receiptFooter : '';
?>
<?php if ($receiptFooter !== ''): ?>
    <p class="slip-thanks"><?= View::text($receiptFooter) ?></p>
<?php endif; ?>
