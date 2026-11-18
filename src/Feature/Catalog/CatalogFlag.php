<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Catalog;

final class CatalogFlag
{
    public static function on(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
