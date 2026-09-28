<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use InvalidArgumentException;
use PerfectConsign\Shared\Scalar\RowValue;

/**
 * Rent, size, and placement facts for one booth list.
 */
final class BoothMeasures
{
    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{boothId: string, vendorId: string, vendorName: string, startsOn: string, endsOn: ?string}>
     */
    public function stays(array $rows): array
    {
        $stays = [];
        foreach ($rows as $row) {
            $stays[] = [
                'boothId' => $this->text($row['booth_id'] ?? null),
                'vendorId' => $this->text($row['vendor_id'] ?? null),
                'vendorName' => $this->text($row['vendor_name'] ?? null),
                'startsOn' => $this->day($row['starts_on'] ?? null),
                'endsOn' => $this->optionalDay($row['ends_on'] ?? null),
            ];
        }

        return $stays;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    public function rent(array $rows, string $today): array
    {
        $chosen = [];
        foreach ($rows as $row) {
            $month = $this->day($row['month_start'] ?? null);
            if ($month > $today) {
                continue;
            }
            $boothId = $this->text($row['booth_id'] ?? null);
            if (isset($chosen[$boothId]) && $chosen[$boothId]['month'] >= $month) {
                continue;
            }
            $chosen[$boothId] = [
                'month' => $month,
                'amount' => RowValue::integer($row['amount_minor'] ?? null),
            ];
        }
        $amounts = [];
        foreach ($chosen as $boothId => $rate) {
            $amounts[$boothId] = $rate['amount'];
        }

        return $amounts;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    public function openBalances(array $rows): array
    {
        $balances = [];
        foreach ($rows as $row) {
            $amount = RowValue::integer($row['open_minor'] ?? null);
            if ($amount <= 0) {
                continue;
            }
            $balances[$this->text($row['booth_id'] ?? null)] = $amount;
        }

        return $balances;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, true>
     */
    public function blocked(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[$this->text($row['id'] ?? null)] = true;
        }

        return $ids;
    }

    /**
     * @return array{width: int, depth: int}
     */
    public function size(mixed $width, mixed $depth, int $defaultWidth, int $defaultDepth): array
    {
        $across = $this->feet($width);
        $back = $this->feet($depth);
        if ($across === null || $back === null) {
            return ['width' => $defaultWidth, 'depth' => $defaultDepth];
        }

        return ['width' => $across, 'depth' => $back];
    }

    public function placed(mixed $x, mixed $z): bool
    {
        return $this->coordinate($x) !== null && $this->coordinate($z) !== null;
    }

    public function requireDate(string $value): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new InvalidArgumentException('today is YYYY-MM-DD');
        }
    }

    private function feet(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $feet = RowValue::integer($value);
        if ($feet <= 0) {
            throw new InvalidArgumentException('booth size is whole feet');
        }

        return $feet;
    }

    private function coordinate(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return RowValue::integer($value);
    }

    private function day(mixed $value): string
    {
        $day = $this->optionalDay($value);
        if ($day === null) {
            throw new InvalidArgumentException('a booth date is YYYY-MM-DD');
        }

        return $day;
    }

    private function optionalDay(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            throw new InvalidArgumentException('a booth date is YYYY-MM-DD');
        }

        return substr($value, 0, 10);
    }

    private function text(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException('a booth field is missing');
        }

        return $value;
    }
}
