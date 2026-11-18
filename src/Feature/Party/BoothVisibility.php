<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Feature\Catalog\CatalogFlag;

/**
 * Booth Active is whether the booth is offered. It is not occupancy.
 */
final class BoothVisibility
{
    public static function chosen(string $raw): string
    {
        return $raw === 'inactive' ? 'inactive' : 'active';
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function only(array $rows, string $visibility): array
    {
        $want = $visibility !== 'inactive';
        $kept = [];
        foreach ($rows as $row) {
            if (CatalogFlag::on($row['active'] ?? true) === $want) {
                $kept[] = $row;
            }
        }

        return $kept;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{active: int, inactive: int}
     */
    public static function counts(array $rows): array
    {
        $active = 0;
        $inactive = 0;
        foreach ($rows as $row) {
            if (CatalogFlag::on($row['active'] ?? true)) {
                $active++;
            } else {
                $inactive++;
            }
        }

        return ['active' => $active, 'inactive' => $inactive];
    }
}
