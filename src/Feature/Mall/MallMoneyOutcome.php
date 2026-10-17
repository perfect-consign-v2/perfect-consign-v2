<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Mall;

final class MallMoneyOutcome
{
    public function __construct(
        public readonly string $storeTake,
        public readonly string $taxRate,
        public readonly string $cashRound,
        public readonly string $error,
    ) {
    }
}
