<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $listBase */
/** @var string $query */
/** @var string $standing */
/** @var int $activeCount */
/** @var int $inactiveCount */

$shows = [
    'active' => 'Active (' . $activeCount . ')',
    'inactive' => 'Inactive (' . $inactiveCount . ')',
];
?>
<div class="list-show mt-6">
    <p class="muted">Show</p>
    <div class="segmented" role="navigation" aria-label="Show">
        <?php foreach ($shows as $value => $label): ?>
            <?php
            $params = [];
            if ($value === 'inactive') {
                $params['status'] = 'inactive';
            }
            if ($query !== '') {
                $params['q'] = $query;
            }
            $href = $listBase . ($params === [] ? '' : '?' . http_build_query($params));
            $selected = $standing === $value;
            ?>
            <a class="seg-btn<?= $selected ? ' is-selected' : '' ?>" href="<?= View::text($href) ?>"<?= $selected ? ' aria-current="page"' : '' ?>><?= View::text($label) ?></a>
        <?php endforeach; ?>
    </div>
</div>
