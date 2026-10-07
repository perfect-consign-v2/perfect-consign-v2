<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

final class LabelCatalog
{
    public const DEFAULT = 'avery_8160';

    public const LIMIT = 240;

    /** @var list<LabelStock>|null */
    private ?array $stocks = null;

    /**
     * @return list<LabelStock>
     */
    public function all(): array
    {
        if ($this->stocks === null) {
            $this->stocks = $this->defined();
        }

        return $this->stocks;
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public function choices(): array
    {
        $choices = [];
        foreach ($this->all() as $stock) {
            $choices[] = ['key' => $stock->getKey(), 'label' => $stock->getLabel()];
        }

        return $choices;
    }

    public function isKnown(string $key): bool
    {
        foreach ($this->all() as $stock) {
            if ($stock->getKey() === $key) {
                return true;
            }
        }

        return false;
    }

    public function get(string $key): LabelStock
    {
        foreach ($this->all() as $stock) {
            if ($stock->getKey() === $key) {
                return $stock;
            }
        }

        throw new \InvalidArgumentException('Choose the stickers to print.');
    }

    /**
     * @return list<LabelStock>
     */
    private function defined(): array
    {
        $sheet = $this->ink(280000, 450000, 110000, 120000, 80000);
        $hang = $this->ink(500000, 800000, 140000, 120000, 120000);

        return [
            new LabelStock(
                self::DEFAULT,
                'Big sheet — 30 stickers (2⅝ by 1 inch)',
                'full',
                $this->box(3, 10, 8500000, 11000000, 500000, 187500, 2625000, 1000000, 125000),
                $sheet,
            ),
            new LabelStock(
                'avery_94073',
                'Small sheet — 50 stickers (1½ by 1 inch)',
                'full',
                $this->box(5, 10, 8500000, 11000000, 500000, 250000, 1500000, 1000000, 130000),
                $sheet,
            ),
            new LabelStock(
                'bcw_consignment',
                'Hang tag — one tag (2⅜ by 3⅜ inch)',
                'full',
                $this->box(1, 1, 2375000, 3375000, 0, 0, 2375000, 3375000, 0),
                $hang,
            ),
            new LabelStock(
                'bcw_consignment_2up',
                'Hang tag — two across',
                'full',
                $this->box(2, 1, 4875000, 3375000, 0, 0, 2375000, 3375000, 125000),
                $hang,
            ),
            new LabelStock(
                'bcw_jewelry',
                'Jewelry tag — thin tag (2¼ by ½ inch)',
                'jewelry',
                $this->box(1, 1, 2250000, 500000, 0, 0, 2250000, 500000, 0),
                $this->ink(160000, 260000, 90000, 80000, 40000),
            ),
        ];
    }

    private function box(
        int $columns,
        int $rows,
        int $pageWidth,
        int $pageHeight,
        int $marginTop,
        int $marginLeft,
        int $width,
        int $height,
        int $columnGap,
    ): LabelBox {
        return new LabelBox(
            $columns,
            $rows,
            $pageWidth,
            $pageHeight,
            $marginTop,
            $marginLeft,
            $width,
            $height,
            $columnGap,
        );
    }

    private function ink(int $barMin, int $barCap, int $codeHeight, int $padX, int $padY): LabelInk
    {
        return new LabelInk($barMin, $barCap, $codeHeight, $padX, $padY);
    }
}
