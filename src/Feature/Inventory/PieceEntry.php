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
        public readonly string $description,
        public readonly int $priceMinor,
        public readonly int $quantity,
        public readonly ?int $commissionBps,
        public readonly PieceDates $dates,
    ) {
    }
}
