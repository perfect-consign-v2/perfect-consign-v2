<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Mall;

use DateTimeImmutable;
use DateTimeZone;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class MallSettings
{
    public function __construct(private readonly Statements $statements)
    {
    }

    public function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone($this->timeZone())))->format('Y-m-d');
    }

    public function timeZone(): string
    {
        return RowValue::string($this->row()['time_zone'] ?? null);
    }

    public function boothWidth(): int
    {
        return RowValue::integer($this->row()['booth_width_ft'] ?? null);
    }

    public function boothDepth(): int
    {
        return RowValue::integer($this->row()['booth_depth_ft'] ?? null);
    }

    public function floorWidth(): int
    {
        return RowValue::integer($this->row()['floor_width_ft'] ?? null);
    }

    public function floorDepth(): int
    {
        return RowValue::integer($this->row()['floor_depth_ft'] ?? null);
    }

    public function storeTake(): int
    {
        return RowValue::integer($this->row()['commission_bps'] ?? null);
    }

    public function save(string $timeZone, int $width, int $depth, int $floorWidth, int $floorDepth, int $storeTake): void
    {
        if (!in_array($timeZone, timezone_identifiers_list(DateTimeZone::ALL_WITH_BC), true)) {
            throw new \InvalidArgumentException('that time zone is not an IANA name');
        }
        if ($width < 1 || $depth < 1) {
            throw new \InvalidArgumentException('booth size is whole feet');
        }
        if ($floorWidth < 1 || $floorDepth < 1) {
            throw new \InvalidArgumentException('floor size is whole feet');
        }
        if ($storeTake < 0 || $storeTake > 10000) {
            throw new \InvalidArgumentException('store take is a percent from 0 to 100');
        }
        $this->statements->change(
            'UPDATE mall SET time_zone = ?, booth_width_ft = ?, booth_depth_ft = ?, floor_width_ft = ?, floor_depth_ft = ?, commission_bps = ?',
            [$timeZone, $width, $depth, $floorWidth, $floorDepth, $storeTake]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(): array
    {
        $rows = $this->statements->rows(
            'SELECT time_zone, booth_width_ft, booth_depth_ft, floor_width_ft, floor_depth_ft, commission_bps FROM mall'
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            throw new \RuntimeException('the mall has no clock');
        }

        return $row;
    }
}
