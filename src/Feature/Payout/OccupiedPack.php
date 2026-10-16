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
     * @param list<array{vendor_id: string, code: string, name: string, booth: string, boothId: string, mode: string, prior: int, charged: int, paid: int, payable: int, rent: int, monthRent: int, check: int, owes: int, settled: int, adjustment: int, note: string}> $pack
     * @return list<array{vendor_id: string, code: string, name: string, booth: string, boothId: string, mode: string, prior: int, charged: int, paid: int, payable: int, rent: int, monthRent: int, check: int, owes: int, settled: int, adjustment: int, note: string}>
     */
    public function fill(array $pack, string $today): array
    {
        $rows = [];
        foreach ($pack as $row) {
            $id = $row['vendor_id'];
            if (!isset($rows[$id])) {
                $rows[$id] = $row;
                continue;
            }
            $rows[$id] = self::withBooth($rows[$id], [
                'vendor_id' => $id,
                'code' => $row['code'],
                'name' => $row['name'],
                'booth' => $row['booth'],
                'booth_id' => $row['boothId'],
            ]);
        }
        foreach ($this->occupied($today) as $stay) {
            $id = $stay['vendor_id'];
            $rows[$id] = self::withBooth($rows[$id] ?? null, $stay);
        }

        return array_values($rows);
    }

    /**
     * @param array{vendor_id: string, code: string, name: string, booth: string, boothId: string, mode: string, prior: int, charged: int, paid: int, payable: int, rent: int, monthRent: int, check: int, owes: int, settled: int, adjustment: int, note: string}|null $row
     * @param array{vendor_id: string, code: string, name: string, booth: string, booth_id: string} $stay
     * @return array{vendor_id: string, code: string, name: string, booth: string, boothId: string, mode: string, prior: int, charged: int, paid: int, payable: int, rent: int, monthRent: int, check: int, owes: int, settled: int, adjustment: int, note: string}
     */
    public static function withBooth(?array $row, array $stay): array
    {
        if ($row === null) {
            return [
                'vendor_id' => $stay['vendor_id'],
                'code' => $stay['code'],
                'name' => $stay['name'],
                'booth' => $stay['booth'],
                'boothId' => $stay['booth_id'],
                'mode' => 'pay',
                'prior' => 0,
                'charged' => 0,
                'paid' => 0,
                'payable' => 0,
                'rent' => 0,
                'monthRent' => 0,
                'check' => 0,
                'owes' => 0,
                'settled' => 0,
                'adjustment' => 0,
                'note' => '',
            ];
        }
        $booth = $stay['booth'];
        if ($booth !== '') {
            $codes = $row['booth'] === '' ? [] : explode(', ', $row['booth']);
            if (!in_array($booth, $codes, true)) {
                $codes[] = $booth;
                usort($codes, static fn (string $left, string $right): int => strnatcasecmp($left, $right));
                $row['booth'] = implode(', ', $codes);
            }
        }
        if ($row['boothId'] === '') {
            $row['boothId'] = $stay['booth_id'];
        }

        return $row;
    }

    /**
     * @return list<array{vendor_id: string, code: string, name: string, booth: string, booth_id: string}>
     */
    private function occupied(string $today): array
    {
        $rows = $this->statements->bound(
            'SELECT vendor.id AS vendor_id, vendor.code, vendor.name, booth.code AS booth, booth.id::text AS booth_id
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
                'booth_id' => RowValue::string($row['booth_id'] ?? null),
            ];
        }

        return $stays;
    }
}
