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

$selectionStorageKey = isset($selectionStorageKey) && is_string($selectionStorageKey) ? $selectionStorageKey : '';
$formId = $printId === '' ? ' id="stickers"' : '';
$labelAction = isset($labelAction) && is_string($labelAction) && $labelAction !== ''
    ? $labelAction
    : '/' . $slug . '/inventory/labels';
?>
<form<?= $formId ?> class="card mt-4" method="post" action="<?= View::text($labelAction) ?>">
    <div class="card-head"><div class="h3">Print stickers</div></div>
    <div class="card-body stack">
        <p class="muted text-sm"><?= View::text($lead) ?></p>
        <div class="sticker-pair sticker-pair-stock">
            <div class="field">
                <label for="stock">Which stickers</label>
                <select class="select" id="stock" name="stock">
                    <?php foreach ($stocks as $stock): ?>
                        <option value="<?= View::text($stock['key']) ?>"<?= $stock['key'] === $labelStock ? ' selected' : '' ?>><?= View::text($stock['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="copies">How many</label>
                <input class="input" id="copies" name="copies" inputmode="numeric" value="1">
                <p class="muted text-sm">For each item. From 1 to 999.</p>
            </div>
        </div>
        <div class="sticker-pair">
            <div class="field">
                <label for="start_row">First empty row</label>
                <input class="input" id="start_row" name="start_row" inputmode="numeric" value="1">
            </div>
            <div class="field">
                <label for="start_column">First empty column</label>
                <input class="input" id="start_column" name="start_column" inputmode="numeric" value="1">
                <p class="muted text-sm">A new sheet starts at row 1, column 1.</p>
            </div>
        </div>
    </div>
    <div class="card-foot">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <?php if ($printId === '' && $selectionStorageKey !== ''): ?>
            <input type="hidden" name="selection_storage_key" value="<?= View::text($selectionStorageKey) ?>">
        <?php endif; ?>
        <?php if ($printId !== ''): ?>
            <input type="hidden" name="item" value="<?= View::text($printId) ?>">
        <?php endif; ?>
        <button class="btn btn-primary" type="submit">Print stickers</button>
    </div>
</form>
