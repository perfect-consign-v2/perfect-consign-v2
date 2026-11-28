<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $settingsPart */

$settingsTabs = [
    'money' => 'Money',
    'building' => 'Building',
    'receipt' => 'Receipt',
    'backup' => 'Backup',
    'counts' => 'Item counts',
    'bad-debts' => 'Bad debts',
];
?>
<div class="list-show mt-4">
    <div class="segmented" role="navigation" aria-label="Settings">
        <?php foreach ($settingsTabs as $tab => $label): ?>
            <?php $selected = $settingsPart === $tab; ?>
            <a class="seg-btn<?= $selected ? ' is-selected' : '' ?>" href="/<?= View::text($slug) ?>/settings/<?= View::text($tab) ?>"<?= $selected ? ' aria-current="page"' : '' ?>><?= View::text($label) ?></a>
        <?php endforeach; ?>
    </div>
</div>
