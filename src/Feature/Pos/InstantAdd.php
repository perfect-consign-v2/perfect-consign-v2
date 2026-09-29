<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

final class InstantAdd
{
    public function __construct(
        public readonly string $vendorId,
        public readonly string $description,
        public readonly string $price,
        public readonly string $barcode,
        public readonly string $quantity,
        public readonly string $brandId,
        public readonly string $brandLabel,
        public readonly string $discountKind,
        public readonly string $discountValue,
    ) {
    }
}
