<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Payout;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class OccupiedPack
{
    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * @param list<array{vendor_id: string, code: string, name: string, booth: string, mode: string, prior: int, charged: int, paid: int, payable: int, rent: int, check: int, owes: int, settled: int, adjustment: int}> $pack
     * @return list<array{vendor_id: string, code: string, name: string, booth: string, mode: string, prior: int, charged: int, paid: int, payable: int, rent: int, check: int, owes: int, settled: int, adjustment: int}>
     */
    public function fill(array $pack, string $today): array
    {
        $seen = [];
        foreach ($pack as $index => $row) {
            $seen[$row['vendor_id'] . '|' . $row['booth']] = $index;
        }
        foreach ($this->occupied($today) as $stay) {
            $key = $stay['vendor_id'] . '|' . $stay['booth'];
            if (isset($seen[$key])) {
                continue;
            }
            $blank = $stay['vendor_id'] . '|';
            if (isset($seen[$blank]) && $pack[$seen[$blank]]['booth'] === '') {
                $pack[$seen[$blank]]['booth'] = $stay['booth'];
                $seen[$key] = $seen[$blank];
                continue;
            }
            $pack[] = [
                'vendor_id' => $stay['vendor_id'],
                'code' => $stay['code'],
                'name' => $stay['name'],
                'booth' => $stay['booth'],
                'mode' => 'pay',
                'prior' => 0,
                'charged' => 0,
                'paid' => 0,
                'payable' => 0,
                'rent' => 0,
                'check' => 0,
                'owes' => 0,
                'settled' => 0,
                'adjustment' => 0,
            ];
        }

        return $pack;
    }

    /**
     * @return list<array{vendor_id: string, code: string, name: string, booth: string}>
     */
    private function occupied(string $today): array
    {
        $rows = $this->statements->bound(
            'SELECT vendor.id AS vendor_id, vendor.code, vendor.name, booth.code AS booth
             FROM booth_assignments AS stay
             JOIN vendors AS vendor ON vendor.id = stay.vendor_id
             JOIN booths AS booth ON booth.id = stay.booth_id
             WHERE stay.starts_on <= ?::date
               AND (stay.ends_on IS NULL OR stay.ends_on >= ?::date)
             ORDER BY vendor.code, booth.code',
            [$today, $today]
        );
        $stays = [];
        foreach ($rows as $row) {
            $stays[] = [
                'vendor_id' => RowValue::string($row['vendor_id'] ?? null),
                'code' => RowValue::string($row['code'] ?? null),
                'name' => RowValue::string($row['name'] ?? null),
                'booth' => RowValue::string($row['booth'] ?? null),
            ];
        }

        return $stays;
    }
}
