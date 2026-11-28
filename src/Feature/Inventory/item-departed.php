<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var array{id: string} $item */
/** @var array{vendor_id: string, vendor_name: string, held_on: string}|null $departed */

?>
<?php if ($departed !== null): ?>
<section class="card mt-6" aria-labelledby="item-departed">
    <div class="card-head"><div class="h3" id="item-departed">This vendor has left</div></div>
    <div class="card-body stack">
        <p>This piece belongs to <?= View::text($departed['vendor_name']) ?>, who has left the store. It cannot be sold.</p>
        <?php if ($departed['held_on'] !== ''): ?>
            <p>Held for <?= View::text($departed['vendor_name']) ?> since <?= View::date($departed['held_on']) ?>. Give it back from the Close out page.</p>
        <?php else: ?>
            <form method="post" action="/<?= View::text($slug) ?>/inventory/<?= View::text($item['id']) ?>/hold">
                <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                <button class="btn btn-primary" type="submit">Hold it for <?= View::text($departed['vendor_name']) ?></button>
            </form>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>
