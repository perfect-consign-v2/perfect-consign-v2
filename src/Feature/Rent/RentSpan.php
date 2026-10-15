<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Rent;

use DateTimeImmutable;

/**
 * The calendar month on the rent book, or one rent period inside the backlog.
 */
final class RentSpan
{
    /**
     * @param list<string> $suffixes
     */
    public function __construct(
        public readonly string $month,
        public readonly string $today,
        public readonly string $start,
        public readonly string $end,
        public readonly int $day,
        public readonly ?string $period,
        public readonly array $suffixes,
    ) {
    }

    public static function cover(string $month, string $today, int $cycleDay, ?string $periodStart): self
    {
        $day = $cycleDay >= 1 && $cycleDay <= 28 ? $cycleDay : 1;
        $cycle = new RentCycle($day);
        if ($periodStart === null) {
            $start = $month . '-01';

            return new self(
                $month,
                $today,
                $start,
                (new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d'),
                $day,
                null,
                $cycle->suffixesTouching($month),
            );
        }

        return new self(
            $month,
            $today,
            $periodStart,
            $cycle->end($periodStart),
            $day,
            $periodStart,
            [$cycle->suffix($periodStart)],
        );
    }
}
