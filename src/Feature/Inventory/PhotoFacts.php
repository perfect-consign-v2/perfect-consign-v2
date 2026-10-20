<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Inventory;

interface PhotoFacts
{
    public function uploaded(string $path): bool;

    public function ini(string $key): string;
}
