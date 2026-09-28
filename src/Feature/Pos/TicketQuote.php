<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

final class TicketQuote
{
    public static function halfUp(int $price, int $bps): int
    {
        return intdiv($price * $bps + 5000, 10000);
    }
}
