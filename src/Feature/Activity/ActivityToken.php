<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Activity;

use DateTimeImmutable;

final class ActivityToken
{
    public static function day(string $value): string|false
    {
        if ($value === '') {
            return '';
        }
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($day === false || $day->format('Y-m-d') !== $value) {
            return false;
        }

        return $value;
    }

    public static function token(string $value): string|false
    {
        if ($value !== '' && preg_match('/^[0-9a-fA-F-]{36}$/', $value) !== 1) {
            return false;
        }

        return $value;
    }

    public static function verb(string $action): string|false
    {
        if ($action !== '' && !ActivityPhrase::has($action)) {
            return false;
        }

        return $action;
    }
}
