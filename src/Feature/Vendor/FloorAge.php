<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Vendor;

/** Whole days from intake through the mall's today, and the vendor-list color band. */
final class FloorAge
{
    public function days(?string $intake, string $today): ?int
    {
        if ($intake === null || $intake === '') {
            return null;
        }
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $intake);
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $today);
        if (
            $start === false
            || $end === false
            || $start->format('Y-m-d') !== $intake
            || $end->format('Y-m-d') !== $today
        ) {
            return null;
        }
        $diff = (int) $start->diff($end)->format('%r%a');
        if ($diff < 0) {
            return 0;
        }

        return $diff;
    }

    public function tier(?int $days, int $quantity): ?int
    {
        if ($days === null || $quantity < 1) {
            return null;
        }
        if ($days <= 30) {
            return 0;
        }
        if ($days <= 60) {
            return 1;
        }
        if ($days <= 90) {
            return 2;
        }

        return 3;
    }
}
