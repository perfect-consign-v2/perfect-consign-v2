<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

final class TicketFlag
{
    public static function on(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
