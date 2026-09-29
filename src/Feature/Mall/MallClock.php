<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Mall;

use DateTimeZone;

final class MallClock
{
    public function __construct(private readonly MallSettings $mall)
    {
    }

    public function localTime(mixed $value): string
    {
        $time = $this->local($value);
        if ($time === null) {
            return is_string($value) ? $value : '';
        }

        return $time->format('M j, Y g:i a');
    }

    public function localDate(mixed $value): string
    {
        $time = $this->local($value);
        if ($time === null) {
            return '';
        }

        return $time->format('Y-m-d');
    }

    private function local(mixed $value): ?\DateTime
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $time = date_create($value);
        if ($time === false) {
            return null;
        }
        $time->setTimezone(new DateTimeZone($this->mall->timeZone()));

        return $time;
    }
}
