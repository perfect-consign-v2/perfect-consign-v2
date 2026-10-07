<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

final class PieceEntry
{
    public function __construct(
        public readonly string $code,
        public readonly string $barcode,
        public readonly string $vendorId,
        public readonly ?string $brandId,
        /** @var array<string, string|null> */
        public readonly array $attributes,
        public readonly string $description,
        public readonly PieceSale $sale,
        public readonly PieceDates $dates,
    ) {
    }
}
