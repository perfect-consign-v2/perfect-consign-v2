<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Payout;

final class PayoutWindow
{
    /**
     * @return array{from: string, to: string, rent: string}
     */
    public function window(string $today): array
    {
        $rent = substr($today, 0, 7) . '-01';
        $to = (new \DateTimeImmutable($rent))->modify('-1 day')->format('Y-m-d');

        return [
            'from' => substr($to, 0, 7) . '-01',
            'to' => $to,
            'rent' => $rent,
        ];
    }
}
