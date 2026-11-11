<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

final class StickerItem
{
    public function __construct(
        public readonly string $barcode,
        public readonly string $price,
        public readonly string $description,
        public readonly string $store,
        public readonly string $consignor,
        public readonly StickerMeta $meta,
    ) {
    }

    public function brand(): string
    {
        return $this->meta->brand;
    }

    public function size(): string
    {
        return $this->meta->size;
    }

    public function intake(): string
    {
        return $this->meta->intake;
    }

    public function tag(): string
    {
        return $this->meta->tag;
    }

    public function deal(): string
    {
        return $this->meta->deal;
    }
}
