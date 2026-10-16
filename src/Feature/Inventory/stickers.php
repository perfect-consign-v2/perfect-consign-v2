<?php

declare(strict_types=1);

use PerfectConsign\Feature\Inventory\StickerSheet;
use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var StickerSheet $sheet */
/** @var string $selectionStorageKey */
$back = isset($back) && is_string($back) && $back !== '' ? $back : '/' . $slug . '/inventory';
$selectionStorageKey = isset($selectionStorageKey) && is_string($selectionStorageKey) ? $selectionStorageKey : '';
?>
<style>
@page { size: <?= View::text($sheet->pageWidth) ?> <?= View::text($sheet->pageHeight) ?>; margin: 0; }
</style>
<div class="no-print row wrap gap-2">
    <button class="btn btn-primary" type="button" data-print<?= $selectionStorageKey !== '' ? ' data-selection-storage-key="' . View::text($selectionStorageKey) . '"' : '' ?>>Print</button>
    <a class="btn" href="<?= View::text($back) ?>">Back</a>
</div>
<p class="no-print muted text-sm mt-4">In the print box, choose Actual size. Do not choose Fit to page.</p>
<?php if ($sheet->isNarrow()): ?>
    <p class="no-print muted text-sm">These bars may be hard to scan on the small stickers. The 30-per-sheet stickers scan more reliably.</p>
<?php endif; ?>
<div class="sticker-desk mt-4">
    <?php foreach ($sheet->pages as $page): ?>
        <div class="sticker-page" style="width:<?= View::text($sheet->pageWidth) ?>;height:<?= View::text($sheet->pageHeight) ?>">
            <?php foreach ($page as $cell): ?>
                <div class="sticker" style="<?= View::text($cell->style) ?>">
                    <?php foreach ($cell->lines as $line): ?>
                        <p class="sticker-line<?= $line->kind === 'price' ? ' sticker-price' : '' ?>" style="height:<?= View::text($line->size) ?>;line-height:<?= View::text($line->size) ?>;font-size:calc(<?= View::text($line->size) ?> * 0.82)"><?= View::text($line->text) ?></p>
                    <?php endforeach; ?>
                    <img class="sticker-bars" style="height:<?= View::text($cell->barHeight) ?>" src="data:image/png;base64,<?= View::text($cell->image) ?>" alt="<?= View::text($cell->barcode) ?>">
                    <p class="sticker-code" style="height:<?= View::text($cell->codeHeight) ?>;line-height:<?= View::text($cell->codeHeight) ?>;font-size:calc(<?= View::text($cell->codeHeight) ?> * 0.75)"><?= View::text($cell->barcode) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</div>
<script>
document.querySelectorAll('[data-print]').forEach(function (button) {
    button.addEventListener('click', function () {
        window.print();
    });
});
</script>
