<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Report;

final class ReportMonth
{
    /**
     * @return array{from: string, to: string}
     */
    public function bounds(string $month): array
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $month, $found) !== 1) {
            throw new \InvalidArgumentException('a month is YYYY-MM');
        }
        $year = (int) $found[1];
        $part = (int) $found[2];
        if (!checkdate($part, 1, $year)) {
            throw new \InvalidArgumentException('a month is YYYY-MM');
        }
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%04d-%02d-01', $year, $part));
        if (!$start instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException('a month is YYYY-MM');
        }
        $last = (int) $start->format('t');

        return [
            'from' => sprintf('%04d-%02d-01', $year, $part),
            'to' => sprintf('%04d-%02d-%02d', $year, $part, $last),
        ];
    }
}
