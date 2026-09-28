<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class MapStore
{
    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * @return list<array{boothId: string, vendorName: string, startsOn: string, endsOn: ?string}>
     */
    public function stays(): array
    {
        $rows = $this->statements->rows(
            'SELECT assignment.booth_id, vendor.name AS vendor_name, assignment.starts_on, assignment.ends_on
             FROM booth_assignments AS assignment
             JOIN vendors AS vendor ON vendor.id = assignment.vendor_id
             ORDER BY assignment.starts_on'
        );
        $stays = [];
        foreach ($rows as $row) {
            $ends = $row['ends_on'] ?? null;
            $stays[] = [
                'boothId' => RowValue::string($row['booth_id'] ?? null),
                'vendorName' => RowValue::string($row['vendor_name'] ?? null),
                'startsOn' => RowValue::string($row['starts_on'] ?? null),
                'endsOn' => is_string($ends) && $ends !== '' ? $ends : null,
            ];
        }

        return $stays;
    }

    /**
     * @return list<array{id: string, name: string, sort: int}>
     */
    public function floors(): array
    {
        $rows = $this->statements->rows(
            'SELECT id, name, sort_order FROM map_floors ORDER BY sort_order, lower(name)'
        );
        $floors = [];
        foreach ($rows as $row) {
            $floors[] = [
                'id' => RowValue::string($row['id'] ?? null),
                'name' => RowValue::string($row['name'] ?? null),
                'sort' => RowValue::integer($row['sort_order'] ?? null),
            ];
        }

        return $floors;
    }

    public function addFloor(string $name): string
    {
        $name = self::floorName($name);
        $count = $this->statements->rows('SELECT count(*) AS total FROM map_floors');
        $total = RowValue::integer($count[0]['total'] ?? null);
        if ($total >= 20) {
            throw new \InvalidArgumentException('a mall keeps at most 20 floors');
        }
        $rows = $this->statements->bound(
            'INSERT INTO map_floors (name, sort_order)
             SELECT ?, COALESCE(MAX(sort_order), -1) + 1 FROM map_floors
             RETURNING id',
            [$name]
        );
        $id = $rows[0]['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new \InvalidArgumentException('the floor was not created');
        }

        return $id;
    }

    public function renameFloor(string $id, string $name): void
    {
        $count = $this->statements->affected(
            'UPDATE map_floors SET name = ? WHERE id = ?::uuid',
            [self::floorName($name), $id]
        );
        if ($count === 0) {
            throw new \InvalidArgumentException('that floor is not in this mall');
        }
    }

    public function removeFloor(string $id): void
    {
        $count = $this->statements->affected('DELETE FROM map_floors WHERE id = ?::uuid', [$id]);
        if ($count === 0) {
            throw new \InvalidArgumentException('that floor is not in this mall');
        }
    }

    /**
     * @param list<array{id: string, floorId: string, x: int, z: int, width: ?int, depth: ?int}> $placed
     */
    public function savePlacements(array $placed): void
    {
        $this->statements->transaction(function () use ($placed): void {
            $this->statements->change('SELECT floor_width_ft FROM mall FOR UPDATE', []);
            $this->statements->change(
                'UPDATE booths SET x_ft = NULL, z_ft = NULL, floor_id = NULL WHERE x_ft IS NOT NULL',
                []
            );
            foreach ($placed as $row) {
                if ($row['width'] === null || $row['depth'] === null) {
                    $count = $this->statements->affected(
                        'UPDATE booths SET floor_id = ?::uuid, x_ft = ?, z_ft = ? WHERE id = ?::uuid',
                        [$row['floorId'], $row['x'], $row['z'], $row['id']]
                    );
                } else {
                    $count = $this->statements->affected(
                        'UPDATE booths SET floor_id = ?::uuid, x_ft = ?, z_ft = ?, width_ft = ?, depth_ft = ? WHERE id = ?::uuid',
                        [$row['floorId'], $row['x'], $row['z'], $row['width'], $row['depth'], $row['id']]
                    );
                }
                if ($count === 0) {
                    throw new \InvalidArgumentException('that booth is not in this mall');
                }
            }
        });
    }

    private static function floorName(string $name): string
    {
        $name = trim($name);
        if (preg_match('/^.{1,40}$/u', $name) !== 1 || $name !== trim($name)) {
            throw new \InvalidArgumentException('a floor name is 1 to 40 characters');
        }

        return $name;
    }
}
