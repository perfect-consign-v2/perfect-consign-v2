<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

final class LabelFace
{
    private const NARROW = 7500;

    public function __construct(private readonly Code128 $codes)
    {
    }

    public function layout(LabelStock $stock, StickerItem $item): StickerFace
    {
        $kept = $this->fit($stock, $this->candidates($stock, $item));
        $used = 0;
        $lines = [];
        foreach ($kept as $line) {
            $used += $line['height'];
            $lines[] = new StickerLine($line['kind'], $line['text'], InchMeasure::text($line['height']));
        }
        $bars = $this->bars($stock, $used);

        return new StickerFace($lines, $bars, $stock->getCodeHeight(), $this->isNarrow($stock, $item->barcode) ? 1 : 0);
    }

    /**
     * @param list<array{kind: string, text: string, height: int}> $candidates
     * @return list<array{kind: string, text: string, height: int}>
     */
    private function fit(LabelStock $stock, array $candidates): array
    {
        $room = $stock->getHeight() - $stock->getPadY() - $stock->getCodeHeight() - $stock->getBarMin();
        $kept = [];
        foreach ($candidates as $line) {
            if ($line['height'] > $room) {
                break;
            }
            $kept[] = $line;
            $room -= $line['height'];
        }

        return $kept;
    }

    private function bars(LabelStock $stock, int $used): int
    {
        $bars = $stock->getBarMin();
        $room = $stock->getHeight() - $stock->getPadY() - $stock->getCodeHeight() - $bars - $used;
        if ($room <= 0) {
            return $bars;
        }
        $cap = $stock->getBarCap() - $bars;
        if ($room > $cap) {
            return $bars + $cap;
        }

        return $bars + $room;
    }

    /**
     * @return list<array{kind: string, text: string, height: int}>
     */
    private function candidates(LabelStock $stock, StickerItem $item): array
    {
        $large = $stock->getHeight() >= 3000000;
        $price = $stock->isJewelry() ? 120000 : ($large ? 280000 : 160000);
        $lines = $this->kept($this->line('price', $item->price, $price, $stock), []);
        if ($stock->isJewelry()) {
            return $lines;
        }

        return $this->details($stock, $item, $lines, $large ? 160000 : 120000, $large ? 120000 : 100000);
    }

    /**
     * @param list<array{kind: string, text: string, height: int}> $lines
     * @return list<array{kind: string, text: string, height: int}>
     */
    private function details(LabelStock $stock, StickerItem $item, array $lines, int $description, int $meta): array
    {
        $lines = $this->kept($this->line('description', $item->description, $description, $stock), $lines);
        $lines = $this->kept($this->line('store', $item->store, $meta, $stock), $lines);
        $lines = $this->kept($this->line('consignor', $this->prefixed('Consignor: ', $item->consignor), $meta, $stock), $lines);
        $lines = $this->kept($this->line('brand', $item->brand, $meta, $stock), $lines);
        $lines = $this->kept($this->line('date', $item->intake, $meta, $stock), $lines);
        if (!$stock->isTagged()) {
            return $lines;
        }

        return $this->kept($this->line('tag', $this->prefixed('Tag ', $item->tag), $meta, $stock), $lines);
    }

    /**
     * @param array{kind: string, text: string, height: int}|null $line
     * @param list<array{kind: string, text: string, height: int}> $lines
     * @return list<array{kind: string, text: string, height: int}>
     */
    private function kept(?array $line, array $lines): array
    {
        if ($line === null) {
            return $lines;
        }
        $lines[] = $line;

        return $lines;
    }

    /**
     * @return array{kind: string, text: string, height: int}|null
     */
    private function line(string $kind, string $text, int $height, LabelStock $stock): ?array
    {
        if ($text === '') {
            return null;
        }

        return ['kind' => $kind, 'text' => $this->clip($text, $stock, $height), 'height' => $height];
    }

    private function prefixed(string $prefix, string $text): string
    {
        if ($text === '') {
            return '';
        }

        return $prefix . $text;
    }

    private function isNarrow(LabelStock $stock, string $barcode): bool
    {
        $modules = $this->codes->modules($barcode);
        $usable = $stock->getWidth() - $stock->getPadX();
        if ($usable < 1 || $modules < 1) {
            return true;
        }

        return intdiv($usable, $modules) < self::NARROW;
    }

    private function clip(string $text, LabelStock $stock, int $lineHeight): string
    {
        $slots = $this->slots($stock->getWidth() - $stock->getPadX(), $lineHeight);
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($chars) || count($chars) <= $slots) {
            return $text;
        }

        return implode('', array_slice($chars, 0, $slots - 1)) . '…';
    }

    private function slots(int $usable, int $lineHeight): int
    {
        $font = intdiv($lineHeight * 3, 4);
        if ($font < 1) {
            $font = 1;
        }
        $char = intdiv($font * 6, 10);
        if ($char < 1) {
            $char = 1;
        }
        $slots = intdiv($usable, $char);
        if ($slots < 4) {
            return 4;
        }

        return $slots;
    }
}
