<?php

declare(strict_types=1);

namespace PerfectConsign\Ledger;

final class JournalSource
{
    public function __construct(
        public readonly string $type,
        public readonly string $reference,
        public readonly string $description,
    ) {
    }
}
