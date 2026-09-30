<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Mall;

use DateTimeImmutable;
use DateTimeZone;
use PerfectConsign\Ledger\BasisPoints;
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

    public function taxRate(): int
    {
        return RowValue::integer($this->row()['tax_bps'] ?? null);
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
        int $taxRate,
    ): void {
        $this->requireZone($timeZone);
        $this->requireFeet($width, $depth, 'booth size is whole feet');
        $this->requireFeet($floorWidth, $floorDepth, 'floor size is whole feet');
        $this->requireTake($storeTake);
        $this->requireTax($taxRate);
        $this->guardDays($floorDays);
        $this->statements->change(
            'UPDATE mall SET time_zone = ?, booth_width_ft = ?, booth_depth_ft = ?, floor_width_ft = ?, floor_depth_ft = ?, commission_bps = ?, floor_days = ?, cash_round_nickel = ?::boolean, tax_bps = ?',
            [$timeZone, $width, $depth, $floorWidth, $floorDepth, $storeTake, $floorDays, $cashRound === '1' ? 'true' : 'false', $taxRate]
        );
    }

    private static function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }

    private function requireZone(string $timeZone): void
    {
        if (!in_array($timeZone, timezone_identifiers_list(DateTimeZone::ALL_WITH_BC), true)) {
            throw new \InvalidArgumentException('that time zone is not an IANA name');
        }
    }

    private function requireFeet(int $width, int $depth, string $message): void
    {
        if ($width < 1 || $depth < 1) {
            throw new \InvalidArgumentException($message);
        }
    }

    private function requireTake(int $storeTake): void
    {
        if ($storeTake < 0 || $storeTake > BasisPoints::PERCENT) {
            throw new \InvalidArgumentException('store take is a percent from 0 to 100');
        }
    }

    private function requireTax(int $taxRate): void
    {
        if ($taxRate < 0 || $taxRate > BasisPoints::TAX) {
            throw new \InvalidArgumentException('tax rate is a percent from 0 to 100');
        }
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
            'SELECT time_zone, booth_width_ft, booth_depth_ft, floor_width_ft, floor_depth_ft, commission_bps, floor_days, cash_round_nickel, tax_bps FROM mall'
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            throw new \RuntimeException('the mall has no clock');
        }

        return $row;
    }
}
