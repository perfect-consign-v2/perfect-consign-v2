<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $countWas */
/** @var string $countNow */
/** @var list<array{id: string, code: string, label: string}> $countReasons */

?>
<section class="card count-why" aria-labelledby="count-why">
    <div class="card-head"><div class="h3" id="count-why">Why are there fewer?</div></div>
    <div class="card-body stack">
        <p>The count goes from <?= View::text($countWas) ?> to <?= View::text($countNow) ?>. Choose one, and the item is saved.</p>
        <p class="row gap-2">
            <?php foreach ($countReasons as $reason): ?>
                <button class="btn" type="submit" name="quantity_reason" value="<?= View::text($reason['id']) ?>"><?= View::text($reason['label']) ?></button>
            <?php endforeach; ?>
        </p>
        <?php if ($countReasons === []): ?>
            <p class="muted">No reason is switched on for you. Ask the owner to switch one on in Settings → Count reasons.</p>
        <?php endif; ?>
    </div>
</section>
