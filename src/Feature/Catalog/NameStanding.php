<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Catalog;

/**
 * The name lists open on active rows. Inactive is the other list.
 */
final class NameStanding
{
    public static function chosen(string $raw): string
    {
        return $raw === 'inactive' ? 'inactive' : 'active';
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{rows: list<array<string, mixed>>, active: int, inactive: int}
     */
    public static function split(array $rows, string $standing): array
    {
        $active = [];
        $inactive = [];
        foreach ($rows as $row) {
            if (CatalogFlag::on($row['active'] ?? true)) {
                $active[] = $row;
            } else {
                $inactive[] = $row;
            }
        }

        return [
            'rows' => $standing === 'inactive' ? $inactive : $active,
            'active' => count($active),
            'inactive' => count($inactive),
        ];
    }
}
