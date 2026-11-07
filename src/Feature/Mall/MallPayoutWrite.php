<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Mall;

final class MallPayoutWrite
{
    public function __construct(
        public readonly string $operatingMode,
        public readonly bool $rentPrepaid,
        public readonly bool $rentalsEnabled,
        public readonly int $cycleDay,
        public readonly bool $consignmentEnabled,
        public readonly bool $storeStockEnabled,
        public readonly bool $requireSecondSignature,
    ) {
    }
}
