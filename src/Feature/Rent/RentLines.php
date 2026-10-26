<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Rent;

use InvalidArgumentException;
use PerfectConsign\Shared\Scalar\RowValue;

/**
 * Booth rows for one rent month.
 */
final class RentLines
{
    /**
     * @param array{
     *   booths: list<array<string, mixed>>,
     *   assignments: list<array<string, mixed>>,
     *   rates: list<array<string, mixed>>,
     *   charges: list<array<string, mixed>>,
     *   open_rent: list<array<string, mixed>>
     * } $facts
     * @return list<array{
     *   id: string,
     *   code: string,
     *   name: string,
     *   vendor_id: ?string,
     *   vendor_name: ?string,
     *   rate_minor: ?int,
     *   charged: bool,
     *   chargeable: bool,
     *   charge_minor: ?int,
     *   journal_id: ?string,
     *   open_minor: int,
     *   charge_date: string,
     *   collect_amount: string
     * }>
     */
    public function build(array $facts, string $month, string $today, int $cycleDay = 1, ?string $periodStart = null): array
    {
        $span = RentSpan::cover($month, $today, $cycleDay, $periodStart);
        $stays = $this->stays($facts['assignments']);
        $rates = $this->rates($facts['rates']);
        $charges = $this->charges($facts['charges'], $span->suffixes);
        $open = $this->openBalances($facts['open_rent']);
        $rows = [];
        foreach ($facts['booths'] as $booth) {
            $row = $this->row($booth, $stays, $rates, $charges, $open, $span);
            if ($row !== null) {
                $rows[] = $row;
            }
        }
        usort(
            $rows,
            static fn (array $left, array $right): int => strnatcasecmp($left['code'], $right['code'])
        );

        return $rows;
    }

    /**
     * @param array<string, mixed> $booth
     * @param list<array{boothId: string, vendorId: string, vendorName: string, startsOn: string, endsOn: ?string}> $stays
     * @param array<string, int> $rates
     * @param array<string, array{journalId: string, amount: int}> $charges
     * @param array<string, int> $open
     * @return array{
     *   id: string,
     *   code: string,
     *   name: string,
     *   vendor_id: ?string,
     *   vendor_name: ?string,
     *   rate_minor: ?int,
     *   charged: bool,
     *   chargeable: bool,
     *   charge_minor: ?int,
     *   journal_id: ?string,
     *   open_minor: int,
     *   charge_date: string,
     *   collect_amount: string
     * }|null
     */
    private function row(
        array $booth,
        array $stays,
        array $rates,
        array $charges,
        array $open,
        RentSpan $span,
    ): ?array {
        $id = $this->text($booth['id'] ?? null);
        $holder = $this->holder($stays, $id, $span->start, $span->end);
        $rate = $rates[$id] ?? null;
        $charge = $charges[$id] ?? null;
        $balance = $open[$id] ?? 0;
        if ($this->blank($rate, $holder, $balance, $charge)) {
            return null;
        }

        return $this->present(
            $id,
            $this->text($booth['code'] ?? null),
            $this->text($booth['name'] ?? null),
            $holder,
            $rate,
            $charge,
            $balance,
            $span,
        );
    }

    /**
     * @param array{vendorId: string, vendorName: string, startsOn: string, endsOn: ?string}|null $holder
     * @param array{journalId: string, amount: int}|null $charge
     */
    private function blank(?int $rate, ?array $holder, int $balance, ?array $charge): bool
    {
        return $rate === null && $holder === null && $balance < 1 && $charge === null;
    }

    /**
     * @param array{vendorId: string, vendorName: string, startsOn: string, endsOn: ?string}|null $holder
     * @param array{journalId: string, amount: int}|null $charge
     * @return array{
     *   id: string,
     *   code: string,
     *   name: string,
     *   vendor_id: ?string,
     *   vendor_name: ?string,
     *   rate_minor: ?int,
     *   charged: bool,
     *   chargeable: bool,
     *   charge_minor: ?int,
     *   journal_id: ?string,
     *   open_minor: int,
     *   charge_date: string,
     *   collect_amount: string
     * }
     */
    private function present(
        string $id,
        string $code,
        string $name,
        ?array $holder,
        ?int $rate,
        ?array $charge,
        int $balance,
        RentSpan $span,
    ): array {
        $vendorId = null;
        $vendorName = null;
        $startsOn = null;
        $endsOn = null;
        if ($holder !== null) {
            $vendorId = $holder['vendorId'];
            $vendorName = $holder['vendorName'];
            $startsOn = $holder['startsOn'];
            $endsOn = $holder['endsOn'];
        }
        $figure = RentFigure::posted(
            $rate,
            $startsOn,
            $endsOn,
            $span->month,
            $span->today,
            $span->day,
            $span->period,
        );
        $chargeDate = $figure['date'];
        $chargeable = $figure['chargeable'];
        $posted = $figure['amount'];
        $chargeMinor = null;
        $journalId = null;
        if ($charge !== null) {
            $chargeMinor = $charge['amount'];
            $journalId = $charge['journalId'];
        }
        $collect = '';
        if ($balance > 0) {
            $collect = intdiv($balance, 100) . '.' . str_pad((string) ($balance % 100), 2, '0', STR_PAD_LEFT);
        }

        return [
            'id' => $id,
            'code' => $code,
            'name' => $name,
            'vendor_id' => $vendorId,
            'vendor_name' => $vendorName,
            'rate_minor' => $posted,
            'charged' => $charge !== null,
            'chargeable' => $chargeable,
            'charge_minor' => $chargeMinor,
            'journal_id' => $journalId,
            'open_minor' => $balance,
            'charge_date' => $chargeDate,
            'collect_amount' => $collect,
        ];
    }

    /**
     * @param list<array{boothId: string, vendorId: string, vendorName: string, startsOn: string, endsOn: ?string}> $stays
     * @return array{boothId: string, vendorId: string, vendorName: string, startsOn: string, endsOn: ?string}|null
     */
    private function holder(array $stays, string $boothId, string $start, string $end): ?array
    {
        $chosen = null;
        foreach ($stays as $stay) {
            if (!$this->covers($stay, $boothId, $start, $end)) {
                continue;
            }
            if ($chosen === null || $stay['startsOn'] >= $chosen['startsOn']) {
                $chosen = $stay;
            }
        }

        return $chosen;
    }

    /**
     * @param array{boothId: string, startsOn: string, endsOn: ?string} $stay
     */
    private function covers(array $stay, string $boothId, string $start, string $end): bool
    {
        if ($stay['boothId'] !== $boothId || $stay['startsOn'] > $end) {
            return false;
        }

        return $stay['endsOn'] === null || $stay['endsOn'] >= $start;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{boothId: string, vendorId: string, vendorName: string, startsOn: string, endsOn: ?string}>
     */
    private function stays(array $rows): array
    {
        $stays = [];
        foreach ($rows as $row) {
            $ends = $row['ends_on'] ?? null;
            $stays[] = [
                'boothId' => $this->text($row['booth_id'] ?? null),
                'vendorId' => $this->text($row['vendor_id'] ?? null),
                'vendorName' => $this->text($row['vendor_name'] ?? null),
                'startsOn' => $this->day($row['starts_on'] ?? null),
                'endsOn' => $this->endDate($ends),
            ];
        }

        return $stays;
    }

    private function endDate(mixed $ends): ?string
    {
        if ($ends === null || $ends === '') {
            return null;
        }

        return $this->day($ends);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function rates(array $rows): array
    {
        $amounts = [];
        foreach ($rows as $row) {
            $amounts[$this->text($row['booth_id'] ?? null)] = RowValue::integer($row['amount_minor'] ?? null);
        }

        return $amounts;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $suffixes
     * @return array<string, array{journalId: string, amount: int}>
     */
    private function charges(array $rows, array $suffixes): array
    {
        $live = [];
        foreach ($rows as $row) {
            $boothId = $this->chargeBooth($row, $suffixes);
            if ($boothId === null) {
                continue;
            }
            $live[$boothId] = [
                'journalId' => $this->text($row['journal_id'] ?? null),
                'amount' => RowValue::integer($row['debit_total_minor'] ?? null),
            ];
        }

        return $live;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $suffixes
     */
    private function chargeBooth(array $row, array $suffixes): ?string
    {
        if (in_array($row['reversed'] ?? false, [true, 1, '1', 't', 'true'], true)) {
            return null;
        }
        $key = $this->text($row['posting_key'] ?? null);
        $prefix = 'rent-charge:';
        if (!str_starts_with($key, $prefix)) {
            return null;
        }
        foreach ($suffixes as $suffix) {
            $tail = ':' . $suffix;
            if (str_ends_with($key, $tail)) {
                return substr($key, strlen($prefix), -strlen($tail));
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function openBalances(array $rows): array
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

    private function day(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            throw new InvalidArgumentException('a rent date is YYYY-MM-DD');
        }

        return substr($value, 0, 10);
    }

    private function text(mixed $value): string
    {
        $text = RowValue::string($value);
        if ($text === '') {
            throw new InvalidArgumentException('a rent field is missing');
        }

        return $text;
    }
}
