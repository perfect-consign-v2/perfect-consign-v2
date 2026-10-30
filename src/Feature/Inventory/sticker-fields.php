<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $labelStock */
/** @var list<array{key: string, label: string}> $stocks */
/** @var string $selectionStorageKey */
/** @var bool $stickerFieldCompact */
/** @var string $stickerFormAttr */

$selectionStorageKey = isset($selectionStorageKey) && is_string($selectionStorageKey) ? $selectionStorageKey : '';
$stickerFieldCompact = ($stickerFieldCompact ?? false) === true;
$stickerFormAttr = isset($stickerFormAttr) && is_string($stickerFormAttr) ? $stickerFormAttr : '';
$formAttr = $stickerFormAttr !== '' ? ' form="' . View::text($stickerFormAttr) . '"' : '';
$stockKey = $selectionStorageKey !== ''
    ? ' data-sticker-stock-key="' . View::text($selectionStorageKey) . '"'
    : '';
?>
<div class="sticker-pair sticker-pair-stock<?= $stickerFieldCompact ? ' sticker-pair-compact' : '' ?>">
    <div class="field">
        <label for="stock">Which stickers</label>
        <select class="select" id="stock" name="stock"<?= $formAttr ?><?= $stockKey ?>>
            <?php foreach ($stocks as $stockOption): ?>
                <option value="<?= View::text($stockOption['key']) ?>"<?= $stockOption['key'] === $labelStock ? ' selected' : '' ?>><?= View::text($stockOption['label']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="copies">How many</label>
        <input class="input" id="copies" name="copies" inputmode="numeric" value="1"<?= $formAttr ?><?= $stickerFieldCompact ? ' title="For each item. From 1 to 999."' : '' ?>>
        <?php if (!$stickerFieldCompact): ?>
            <p class="muted text-sm">For each item. From 1 to 999.</p>
        <?php endif; ?>
    </div>
</div>
<div class="sticker-pair<?= $stickerFieldCompact ? ' sticker-pair-compact' : '' ?>">
    <div class="field">
        <label for="start_row">First empty row</label>
        <input class="input" id="start_row" name="start_row" inputmode="numeric" value="1"<?= $formAttr ?>>
    </div>
    <div class="field">
        <label for="start_column">First empty column</label>
        <input class="input" id="start_column" name="start_column" inputmode="numeric" value="1"<?= $formAttr ?><?= $stickerFieldCompact ? ' title="A new sheet starts at row 1, column 1."' : '' ?>>
        <?php if (!$stickerFieldCompact): ?>
            <p class="muted text-sm">A new sheet starts at row 1, column 1.</p>
        <?php endif; ?>
    </div>
</div>
