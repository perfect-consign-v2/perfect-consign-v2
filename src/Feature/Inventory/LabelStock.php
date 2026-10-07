<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

final class LabelStock
{
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly string $face,
        private readonly LabelBox $box,
        private readonly LabelInk $ink,
    ) {
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function isJewelry(): bool
    {
        return $this->face === 'jewelry';
    }

    public function isTagged(): bool
    {
        return $this->key === 'avery_8160'
            || $this->key === 'bcw_consignment'
            || $this->key === 'bcw_consignment_2up';
    }

    public function getColumns(): int
    {
        return $this->box->columns;
    }

    public function getRows(): int
    {
        return $this->box->rows;
    }

    public function getPageWidth(): int
    {
        return $this->box->pageWidth;
    }

    public function getPageHeight(): int
    {
        return $this->box->pageHeight;
    }

    public function getMarginTop(): int
    {
        return $this->box->marginTop;
    }

    public function getMarginLeft(): int
    {
        return $this->box->marginLeft;
    }

    public function getWidth(): int
    {
        return $this->box->width;
    }

    public function getHeight(): int
    {
        return $this->box->height;
    }

    public function getColumnGap(): int
    {
        return $this->box->columnGap;
    }

    public function getRowGap(): int
    {
        return 0;
    }

    public function getBarMin(): int
    {
        return $this->ink->barMin;
    }

    public function getBarCap(): int
    {
        return $this->ink->barCap;
    }

    public function getCodeHeight(): int
    {
        return $this->ink->codeHeight;
    }

    public function getPadX(): int
    {
        return $this->ink->padX;
    }

    public function getPadY(): int
    {
        return $this->ink->padY;
    }
}
