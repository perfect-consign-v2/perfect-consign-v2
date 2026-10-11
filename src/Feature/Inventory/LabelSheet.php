<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

final class LabelSheet
{
    public function __construct(
        private readonly Code128 $codes,
        private readonly BarcodePng $pngs,
        private readonly LabelFace $faces,
    ) {
    }

    /**
     * @param list<StickerItem> $items
     */
    public function compose(LabelStock $stock, array $items, int $copies, int $row, int $column): StickerSheet
    {
        $ready = $this->ready($stock, $items);
        $sequence = $this->expand($ready, $copies);
        $columns = $stock->getColumns();
        $slots = $columns * $stock->getRows();
        $skip = ($row - 1) * $columns + ($column - 1);
        $pageCount = intdiv($skip + count($sequence) + $slots - 1, $slots);
        $pages = [];
        $images = [];
        for ($page = 0; $page < $pageCount; $page++) {
            $pages[] = $this->page($stock, $sequence, $page * $slots, $slots, $skip, $columns, $images);
        }

        return new StickerSheet(
            InchMeasure::text($stock->getPageWidth()),
            InchMeasure::text($stock->getPageHeight()),
            $this->thin($ready),
            $pages,
        );
    }

    /**
     * @param list<StickerItem> $items
     * @return list<array{item: StickerItem, face: StickerFace}>
     */
    private function ready(LabelStock $stock, array $items): array
    {
        $ready = [];
        foreach ($items as $item) {
            $ready[] = ['item' => $item, 'face' => $this->faces->layout($stock, $item)];
        }

        return $ready;
    }

    /**
     * @param list<array{item: StickerItem, face: StickerFace}> $ready
     */
    private function thin(array $ready): int
    {
        foreach ($ready as $item) {
            if ($item['face']->isNarrow()) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * @param list<array{item: StickerItem, face: StickerFace}> $ready
     * @return list<array{item: StickerItem, face: StickerFace}>
     */
    private function expand(array $ready, int $copies): array
    {
        $sequence = [];
        foreach ($ready as $item) {
            for ($copy = 0; $copy < $copies; $copy++) {
                $sequence[] = $item;
            }
        }

        return $sequence;
    }

    /**
     * @param list<array{item: StickerItem, face: StickerFace}> $sequence
     * @param array<string, string> $images
     * @return list<StickerCell>
     */
    private function page(
        LabelStock $stock,
        array $sequence,
        int $origin,
        int $slots,
        int $skip,
        int $columns,
        array &$images,
    ): array {
        $cells = [];
        $end = $skip + count($sequence);
        for ($slot = 0; $slot < $slots; $slot++) {
            $index = $origin + $slot;
            if ($index < $skip || $index >= $end) {
                continue;
            }
            $item = $sequence[$index - $skip];
            $cells[] = $this->cell(
                $stock,
                $item['item'],
                $item['face'],
                $slot % $columns,
                intdiv($slot, $columns),
                $images,
            );
        }

        return $cells;
    }

    /**
     * @param array<string, string> $images
     */
    private function cell(
        LabelStock $stock,
        StickerItem $item,
        StickerFace $face,
        int $column,
        int $row,
        array &$images,
    ): StickerCell {
        $left = $stock->getMarginLeft() + $column * ($stock->getWidth() + $stock->getColumnGap());
        $top = $stock->getMarginTop() + $row * ($stock->getHeight() + $stock->getRowGap());
        $pad = InchMeasure::text(intdiv($stock->getPadY(), 2)) . ' ' . InchMeasure::text(intdiv($stock->getPadX(), 2));
        [$ppm, $pixels] = $this->pixels($stock, $this->codes->modules($item->barcode), $face->bars);

        return new StickerCell(
            'left:' . InchMeasure::text($left)
                . ';top:' . InchMeasure::text($top)
                . ';width:' . InchMeasure::text($stock->getWidth())
                . ';height:' . InchMeasure::text($stock->getHeight())
                . ';padding:' . $pad,
            $face->lines,
            $this->image($item, $ppm, $pixels, $images),
            InchMeasure::text($face->bars),
            $item->barcode,
            InchMeasure::text($face->code),
        );
    }

    /**
     * @param array<string, string> $images
     */
    private function image(StickerItem $item, int $ppm, int $pixels, array &$images): string
    {
        $key = $item->barcode . ':' . $ppm . ':' . $pixels;
        $cached = $images[$key] ?? null;
        if (is_string($cached)) {
            return $cached;
        }
        $drawn = base64_encode($this->pngs->draw($this->codes->bits($item->barcode), $ppm, $pixels));
        $images[$key] = $drawn;

        return $drawn;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function pixels(LabelStock $stock, int $modules, int $bars): array
    {
        $usable = $stock->getWidth() - $stock->getPadX();
        $ppm = intdiv(intdiv($usable * 300, 1000000), $modules);
        if ($ppm < 2) {
            $ppm = 2;
        }
        if ($ppm > 6) {
            $ppm = 6;
        }
        $height = intdiv($bars * 300, 1000000);
        if ($height < 8) {
            $height = 8;
        }

        return [$ppm, $height];
    }
}
