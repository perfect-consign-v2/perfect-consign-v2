<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Report;

use DateTimeImmutable;

final class ReportPreset
{
    /**
     * @return array{0: string, 1: string}
     */
    public function range(string $range, string $today): array
    {
        if ($range === 'today') {
            return [$today, $today];
        }
        if ($range === 'yesterday') {
            $date = $this->shift($today, '-1 day');

            return [$date, $date];
        }
        if ($range === 'this_week') {
            return [$this->monday($today), $today];
        }
        if ($range === 'last_month') {
            return $this->month($today, '-1 month');
        }

        return $this->month($today, 'this month');
    }

    public function shift(string $today, string $modifier): string
    {
        return (new DateTimeImmutable($today))->modify($modifier)->format('Y-m-d');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function month(string $today, string $which): array
    {
        $first = (new DateTimeImmutable($today))->modify('first day of this month');
        if ($which === '-1 month') {
            $first = $first->modify('-1 month');
        }

        return [$first->format('Y-m-d'), $first->format('Y-m-t')];
    }

    private function monday(string $today): string
    {
        $day = new DateTimeImmutable($today);

        return $day->modify('-' . ((int) $day->format('N') - 1) . ' days')->format('Y-m-d');
    }
}
