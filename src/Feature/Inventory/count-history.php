<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var list<string> $countHistory */

?>
<?php if ($countHistory !== []): ?>
<section class="card mt-6" aria-labelledby="count-history">
    <div class="card-head"><div class="h3" id="count-history">Count history</div></div>
    <div class="card-body">
        <ul class="stack text-sm">
            <?php foreach ($countHistory as $line): ?>
                <li><?= View::text($line) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</section>
<?php endif; ?>
