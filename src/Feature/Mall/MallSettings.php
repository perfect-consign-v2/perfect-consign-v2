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

    public function localTime(mixed $value): string
    {
        $time = $this->local($value);
        if ($time === null) {
            return is_string($value) ? $value : '';
        }

        return $time->format('M j, Y g:i a');
    }

    public function localDate(mixed $value): string
    {
        $time = $this->local($value);
        if ($time === null) {
            return '';
        }

        return $time->format('Y-m-d');
    }

    private function local(mixed $value): ?\DateTime
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $time = date_create($value);
        if ($time === false) {
            return null;
        }
        $time->setTimezone(new DateTimeZone($this->timeZone()));

        return $time;
    }

    /**
     * @return array{width: int, depth: int}
     */
    public function boothSize(): array
    {
        $row = $this->row();

        return [
            'width' => RowValue::integer($row['booth_width_ft'] ?? null),
            'depth' => RowValue::integer($row['booth_depth_ft'] ?? null),
        ];
    }

    /**
     * @return array{width: int, depth: int}
     */
    public function floorSize(): array
    {
        $row = $this->row();

        return [
            'width' => RowValue::integer($row['floor_width_ft'] ?? null),
            'depth' => RowValue::integer($row['floor_depth_ft'] ?? null),
        ];
    }

    public function storeTake(): int
    {
        return RowValue::integer($this->row()['commission_bps'] ?? null);
    }

    public function floorDays(): ?int
    {
        $days = $this->row()['floor_days'] ?? null;
        if ($days === null) {
            return null;
        }

        return RowValue::integer($days);
    }

    public function cashRoundNickel(): bool
    {
        return self::flag($this->row()['cash_round_nickel'] ?? null);
    }

    public function save(
        string $timeZone,
        int $width,
        int $depth,
        int $floorWidth,
        int $floorDepth,
        int $storeTake,
        ?int $floorDays,
        string $cashRound,
    ): void {
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
        $this->guardDays($floorDays);
        $this->statements->change(
            'UPDATE mall SET time_zone = ?, booth_width_ft = ?, booth_depth_ft = ?, floor_width_ft = ?, floor_depth_ft = ?, commission_bps = ?, floor_days = ?, cash_round_nickel = ?::boolean',
            [$timeZone, $width, $depth, $floorWidth, $floorDepth, $storeTake, $floorDays, $cashRound === '1' ? 'true' : 'false']
        );
    }

    private static function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }

    private function guardDays(?int $floorDays): void
    {
        if ($floorDays !== null && ($floorDays < 1 || $floorDays > 365)) {
            throw new \InvalidArgumentException('days on the floor is a whole number from 1 to 365, or empty');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function row(): array
    {
        $rows = $this->statements->rows(
            'SELECT time_zone, booth_width_ft, booth_depth_ft, floor_width_ft, floor_depth_ft, commission_bps, floor_days, cash_round_nickel FROM mall'
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            throw new \RuntimeException('the mall has no clock');
        }

        return $row;
    }
}
