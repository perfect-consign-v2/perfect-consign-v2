<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pc1Mirror;

/**
 * Port of PC1 BoothRentBillingCalendar for extract-only use.
 */
final class Pc1BillingCalendar
{
    public function startOfBillingMonthContaining(\DateTimeImmutable $date, int $cycleDay): \DateTimeImmutable
    {
        $y = (int) $date->format('Y');
        $m = (int) $date->format('m');
        $d = (int) $date->format('d');
        $lastDay = (int) $date->format('t');
        $effectiveDay = min($cycleDay, $lastDay);
        if ($d >= $effectiveDay) {
            return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $m, $effectiveDay));
        }
        $prev = $date->modify('first day of last month');
        $prevLast = (int) $prev->format('t');
        $prevEffective = min($cycleDay, $prevLast);

        return new \DateTimeImmutable(sprintf(
            '%04d-%02d-%02d',
            (int) $prev->format('Y'),
            (int) $prev->format('m'),
            $prevEffective
        ));
    }

    public function endOfBillingMonthContaining(\DateTimeImmutable $date, int $cycleDay): \DateTimeImmutable
    {
        $start = $this->startOfBillingMonthContaining($date, $cycleDay);
        $next = $start->modify('+1 month');
        $nextEffective = min($cycleDay, (int) $next->format('t'));
        $nextStart = new \DateTimeImmutable(sprintf(
            '%04d-%02d-%02d',
            (int) $next->format('Y'),
            (int) $next->format('m'),
            $nextEffective
        ));

        return $nextStart->modify('-1 day');
    }
}
