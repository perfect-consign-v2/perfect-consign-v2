<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pc1Mirror;

final class AssignmentMasterLoader
{
    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, string> $vendors
     * @param array<string, string> $booths
     */
    public function load(MirrorMall $mall, array $rows, array $vendors, array $booths): int
    {
        $count = 0;
        foreach ($rows as $row) {
            $booth = $booths[MirrorFields::text($row['pc1_booth_id'] ?? null)] ?? null;
            $vendor = $vendors[MirrorFields::text($row['pc1_consignor_id'] ?? null)] ?? null;
            if ($booth === null || $vendor === null) {
                continue;
            }
            $starts = MirrorFields::text($row['starts_on'] ?? null);
            $ends = MirrorFields::text($row['ends_on'] ?? null);
            $rent = MirrorFields::cents($row['rent_minor'] ?? 0);
            if (!$this->acceptsStay($starts, $ends, $rent)) {
                continue;
            }
            try {
                $mall->assignments->addAssignment($booth, $vendor, $starts, $ends, $rent);
            } catch (\Throwable) {
                // Prod history can overlap or violate PC2 occupancy rules; skip that stay.
                continue;
            }
            $count++;
        }

        return $count;
    }

    private function acceptsStay(string $starts, string $ends, int $rent): bool
    {
        if ($starts === '' || $rent < 1) {
            return false;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $starts) !== 1) {
            return false;
        }
        if ($ends === '') {
            return true;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $ends) === 1 && $starts <= $ends;
    }
}
