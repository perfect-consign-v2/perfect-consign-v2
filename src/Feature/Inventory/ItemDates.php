<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

final class ItemDates
{
    public function __construct(
        public readonly string $intakeOn,
        public readonly ?string $expiresOn,
    ) {
    }
}
