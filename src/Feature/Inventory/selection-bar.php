<?php

declare(strict_types=1);

/** @var string $labelStock */
/** @var list<array{key: string, label: string}> $stocks */
/** @var string $selectionStorageKey */

$selectionStorageKey = isset($selectionStorageKey) && is_string($selectionStorageKey) ? $selectionStorageKey : '';
$stickerFieldCompact = true;
$stickerFormAttr = 'stickers';
?>
<div class="inventory-selection-bar" data-inventory-selection-bar hidden>
    <div class="inventory-selection-bar__fields">
        <?php require __DIR__ . '/sticker-fields.php'; ?>
    </div>
    <div class="inventory-selection-bar__actions">
        <span data-inventory-selection-count="paren">(0 selected)</span>
        <button class="btn btn-primary" type="submit" form="stickers">Print stickers</button>
        <button class="btn" type="button" data-inventory-selection-clear>Clear</button>
    </div>
</div>
