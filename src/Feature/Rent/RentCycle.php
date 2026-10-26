<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Rent;

use DateTimeImmutable;
use InvalidArgumentException;
use PerfectConsign\Feature\Party\MoveInRent;

/**
 * One rent period. Day 1 is a calendar month. Any other day runs from that day through the day before it next month.
 */
final class RentCycle
{
    public function __construct(private readonly int $day)
    {
        if ($day < 1 || $day > 28) {
            throw new InvalidArgumentException('the rent cycle day is a whole number from 1 to 28');
        }
    }

    public function day(): int
    {
        return $this->day;
    }

    public function start(string $date): string
    {
        if ($this->day === 1) {
            return substr($date, 0, 7) . '-01';
        }
        $monthStart = substr($date, 0, 8) . '01';
        $anchor = $this->shift($monthStart, $this->day - 1);
        if ($date >= $anchor) {
            return $anchor;
        }

        return $this->shift((new DateTimeImmutable($monthStart))->modify('-1 month')->format('Y-m-d'), $this->day - 1);
    }

    public function end(string $periodStart): string
    {
        return (new DateTimeImmutable($periodStart))->modify('+1 month')->modify('-1 day')->format('Y-m-d');
    }

    public function suffix(string $periodStart): string
    {
        if ($this->day === 1) {
            return substr($periodStart, 0, 7);
        }

        return $periodStart;
    }

    /**
     * @return array{from: string, to: string, rent: string}
     */
    public function payout(string $today): array
    {
        $rent = $this->start($today);
        $to = (new DateTimeImmutable($rent))->modify('-1 day')->format('Y-m-d');

        return [
            'from' => $this->start($to),
            'to' => $to,
            'rent' => $rent,
        ];
    }

    public function amount(int $monthly, string $startsOn, string $postingDate): int
    {
        if ($this->day === 1) {
            return MoveInRent::charge($monthly, $startsOn, substr($postingDate, 0, 7));
        }
        $start = $this->start($postingDate);
        $end = $this->end($start);
        if ($startsOn <= $start || $startsOn > $end) {
            return $monthly;
        }

        return intdiv($this->span($startsOn, $end) * $monthly, $this->span($start, $end));
    }

    /**
     * @return list<string>
     */
    public function suffixesTouching(string $month): array
    {
        if ($this->day === 1) {
            return [$month];
        }
        $start = $month . '-01';
        $end = (new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
        $suffixes = [];
        $cursor = $this->start($start);
        while ($cursor <= $end) {
            $suffixes[] = $this->suffix($cursor);
            $cursor = (new DateTimeImmutable($cursor))->modify('+1 month')->format('Y-m-d');
        }

        return $suffixes;
    }

    public function sentence(bool $fromSales): string
    {
        $open = $this->ordinal($this->day);
        $period = $this->day === 1
            ? 'Rent starts on the ' . $open . '. A period is a calendar month.'
            : 'Rent starts on the ' . $open . '. A period runs the ' . $open . ' through the ' . $this->ordinal($this->day - 1) . '.';
        $collect = $fromSales
            ? 'The previous period\'s sales pay this period\'s rent. Anything sales do not cover is collected at the register.'
            : 'Pay vendors their sales. Collect rent at the register.';

        return $period . ' ' . $collect;
    }

    private function shift(string $date, int $days): string
    {
        if ($days === 0) {
            return $date;
        }

        return (new DateTimeImmutable($date))->modify('+' . $days . ' days')->format('Y-m-d');
    }

    private function span(string $from, string $to): int
    {
        return (int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days + 1;
    }

    private function ordinal(int $day): string
    {
        $mod = $day % 100;
        if ($mod >= 11 && $mod <= 13) {
            return $day . 'th';
        }

        return $day . match ($day % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };
    }
}
