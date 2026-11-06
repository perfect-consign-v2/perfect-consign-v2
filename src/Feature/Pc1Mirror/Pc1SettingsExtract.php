<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pc1Mirror;

use PDO;

final class Pc1SettingsExtract
{
    /**
     * @return array{
     *     booth_rent_cycle_day: int,
     *     tax_bps: int,
     *     store_timezone: string,
     *     location_tax_rates: list<mixed>
     * }
     */
    public function read(mixed $raw, PDO $pdo): array
    {
        $decoded = $this->decode($raw);
        $cycle = $decoded['booth_rent_cycle_day'] ?? 1;

        return [
            'booth_rent_cycle_day' => max(1, min(31, is_numeric($cycle) ? (int) $cycle : 1)),
            'tax_bps' => 0,
            'store_timezone' => is_string($decoded['store_timezone'] ?? null)
                ? MirrorFields::text($decoded['store_timezone'])
                : 'America/Los_Angeles',
            'location_tax_rates' => $this->locationRates($pdo),
        ];
    }

    /**
     * @return array<mixed>
     */
    private function decode(mixed $raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $parsed = json_decode($raw, true);

            return is_array($parsed) ? $parsed : [];
        }
        if (is_array($raw)) {
            return $raw;
        }

        return [];
    }

    /**
     * @return list<mixed>
     */
    private function locationRates(PDO $pdo): array
    {
        $location = $pdo->query('SELECT tax_rates FROM locations ORDER BY created_at NULLS LAST LIMIT 1');
        if ($location === false) {
            return [];
        }
        $taxJson = $location->fetchColumn();
        if (!is_string($taxJson) || $taxJson === '') {
            return [];
        }
        $parsedRates = json_decode($taxJson, true);

        return is_array($parsedRates) ? array_values($parsedRates) : [];
    }
}
