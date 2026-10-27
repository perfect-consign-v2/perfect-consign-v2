<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $templateDirectory */
/** @var string $slug */
/** @var string $displayName */
/** @var array{number: string, posted_on: string, amount: int, balance: int, voided: bool, label?: string} $slip */
$slipLabel = is_string($slip['label'] ?? null) ? $slip['label'] : 'Gift certificate';
$soldOn = DateTimeImmutable::createFromFormat('!Y-m-d', $slip['posted_on']);
$when = $soldOn instanceof DateTimeImmutable ? $soldOn->format('m-d-Y') : View::date($slip['posted_on']);
?>
<div class="slip-page">
    <div class="no-print row wrap gap-2">
        <button class="btn btn-primary" type="button" data-print>Print receipt</button>
        <a class="btn" href="/<?= View::text($slug) ?>/pos">Back to register</a>
    </div>
    <article class="slip">
        <?php
        $kicker = $slipLabel;
        require $templateDirectory . '/Shared/Web/partials/slip-head.php';
        ?>
        <?php if ($slip['voided']): ?>
            <p class="slip-void">Voided</p>
        <?php endif; ?>
        <p class="slip-row"><span>Date</span><span><?= View::text($when) ?></span></p>
        <p class="slip-row slip-total"><span>Amount</span><span><?= View::text(View::money($slip['amount'])) ?></span></p>
        <p class="slip-row"><span>Balance left</span><span><?= View::text(View::money($slip['balance'])) ?></span></p>
        <hr class="slip-rule">
        <p class="slip-kicker slip-center">Redemption number</p>
        <p class="slip-number"><?= View::text($slip['number']) ?></p>
        <p class="slip-thanks">Give this number at the register to redeem.</p>
    </article>
</div>
<script src="/assets/js/print-trigger.js"></script>
