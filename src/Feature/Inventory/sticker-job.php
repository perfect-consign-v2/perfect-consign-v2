<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var string $labelStock */
/** @var string $printId */
/** @var string $lead */
/** @var list<array{key: string, label: string}> $stocks */
/** @var string $selectionStorageKey */
/** @var string $labelAction */
/** @var string $stickerChrome */

$selectionStorageKey = isset($selectionStorageKey) && is_string($selectionStorageKey) ? $selectionStorageKey : '';
$stickerChrome = isset($stickerChrome) && is_string($stickerChrome) && $stickerChrome === 'shell'
    ? 'shell'
    : 'card';
$formId = $printId === '' ? ' id="stickers"' : '';
$labelAction = isset($labelAction) && is_string($labelAction) && $labelAction !== ''
    ? $labelAction
    : '/' . $slug . '/inventory/labels';

if ($stickerChrome === 'shell') {
    ?>
<form<?= $formId ?> class="sticker-job-shell" method="post" action="<?= View::text($labelAction) ?>">
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
    <?php if ($selectionStorageKey !== ''): ?>
        <input type="hidden" name="selection_storage_key" value="<?= View::text($selectionStorageKey) ?>">
    <?php endif; ?>
</form>
    <?php
    return;
}

$stickerFieldCompact = false;
$stickerFormAttr = '';
?>
<form<?= $formId ?> class="card mt-4" method="post" action="<?= View::text($labelAction) ?>">
    <div class="card-head"><div class="h3">Print stickers</div></div>
    <div class="card-body stack">
        <p class="muted text-sm"><?= View::text($lead) ?></p>
        <?php require __DIR__ . '/sticker-fields.php'; ?>
    </div>
    <div class="card-foot">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <?php if ($printId !== ''): ?>
            <input type="hidden" name="item" value="<?= View::text($printId) ?>">
        <?php endif; ?>
        <div class="row gap-3">
            <button class="btn btn-primary" type="submit">Print stickers</button>
        </div>
    </div>
</form>
