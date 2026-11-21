<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Web;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use InvalidArgumentException;

final class NavClock
{
    private readonly DateTimeZone $zone;

    public function __construct(string $timeZone)
    {
        if ($timeZone === '') {
            throw new InvalidArgumentException('nav clock needs a time zone');
        }
        try {
            $this->zone = new DateTimeZone($timeZone);
        } catch (Exception) {
            throw new InvalidArgumentException('nav clock time zone is not recognized');
        }
    }

    /**
     * @return array{date: string, time: string, instant: string}
     */
    public function face(?DateTimeImmutable $now = null): array
    {
        $local = $this->local($now);

        return [
            'date' => $local->format('D, M j, Y'),
            'time' => $local->format('g:i:s A'),
            'instant' => $local->format(DateTimeInterface::ATOM),
        ];
    }

    private function local(?DateTimeImmutable $now): DateTimeImmutable
    {
        $now ??= new DateTimeImmutable('now', $this->zone);

        return $now->setTimezone($this->zone);
    }
}
