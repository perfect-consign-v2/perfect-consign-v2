<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Payout;

use PerfectConsign\Feature\Rent\RentCycle;

final class PayoutWindow
{
    /**
     * @return array{from: string, to: string, rent: string}
     */
    public function window(string $today, int $cycleDay = 1): array
    {
        $day = $cycleDay >= 1 && $cycleDay <= 28 ? $cycleDay : 1;

        return (new RentCycle($day))->payout($today);
    }
}
