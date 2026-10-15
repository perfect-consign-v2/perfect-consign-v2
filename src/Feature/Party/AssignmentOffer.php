<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use DateTimeImmutable;
use InvalidArgumentException;
use PerfectConsign\Ledger\Money;
use PerfectConsign\Shared\Http\View;

final class AssignmentOffer
{
    public function __construct(private readonly PartyStore $parties)
    {
    }

    public function contract(string $boothId, string $typed): int
    {
        if ($typed === '') {
            $asking = $this->parties->askingRent($boothId);
            if ($asking === null) {
                throw new InvalidArgumentException('that booth has no asking rent');
            }

            return $asking;
        }

        return Money::parseMinor($typed);
    }

    /**
     * @return list<array{id: string, code: string, rent: string, available_on: string}>
     */
    public function booths(string $today): array
    {
        $this->requireDate($today);
        $choices = [];
        foreach ($this->listed($this->parties->booths(), $this->staysByBooth($this->parties->assignments()), $today) as $booth) {
            $minor = $this->parties->askingRent($booth['id']);
            $choices[] = [
                'id' => $booth['id'],
                'code' => $booth['code'],
                'rent' => $minor === null ? '' : View::money($minor),
                'available_on' => $booth['available_on'],
            ];
        }

        return $choices;
    }

    /**
     * @param list<array<string, mixed>> $booths
     * @param array<string, list<array{startsOn: string, endsOn: ?string}>> $stays
     * @return list<array{id: string, code: string, available_on: string}>
     */
    private function listed(array $booths, array $stays, string $today): array
    {
        $listed = [];
        foreach ($booths as $booth) {
            $id = $this->text($booth['id'] ?? null);
            $available = $this->availableOn($stays[$id] ?? [], $today);
            if ($available === null) {
                continue;
            }
            $listed[] = [
                'id' => $id,
                'code' => $this->text($booth['code'] ?? null),
                'available_on' => $available,
            ];
        }

        return $listed;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, list<array{startsOn: string, endsOn: ?string}>>
     */
    private function staysByBooth(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$this->text($row['booth_id'] ?? null)][] = [
                'startsOn' => $this->day($row['starts_on'] ?? null),
                'endsOn' => $this->optionalDay($row['ends_on'] ?? null),
            ];
        }

        return $grouped;
    }

    /**
     * Empty means the booth is free today. Null means it never comes free.
     *
     * @param list<array{startsOn: string, endsOn: ?string}> $stays
     */
    private function availableOn(array $stays, string $today): ?string
    {
        $cursor = $today;
        $left = count($stays) + 1;
        while ($left > 0) {
            $left--;
            $covering = $this->covering($stays, $cursor);
            if ($covering === null) {
                return $cursor === $today ? '' : $cursor;
            }
            if ($covering['endsOn'] === null) {
                return null;
            }
            $cursor = $this->nextDay($covering['endsOn']);
        }

        throw new InvalidArgumentException('a booth stay does not end');
    }

    /**
     * @param list<array{startsOn: string, endsOn: ?string}> $stays
     * @return array{startsOn: string, endsOn: ?string}|null
     */
    private function covering(array $stays, string $cursor): ?array
    {
        $found = null;
        foreach ($stays as $stay) {
            if (!$this->covers($stay, $cursor)) {
                continue;
            }
            if ($found === null || $this->endsLater($stay, $found)) {
                $found = $stay;
            }
        }

        return $found;
    }

    /**
     * @param array{startsOn: string, endsOn: ?string} $stay
     */
    private function covers(array $stay, string $cursor): bool
    {
        if ($stay['startsOn'] > $cursor) {
            return false;
        }
        if ($stay['endsOn'] !== null && $stay['endsOn'] < $cursor) {
            return false;
        }

        return true;
    }

    /**
     * @param array{startsOn: string, endsOn: ?string} $stay
     * @param array{startsOn: string, endsOn: ?string} $found
     */
    private function endsLater(array $stay, array $found): bool
    {
        if ($stay['endsOn'] === null) {
            return true;
        }
        if ($found['endsOn'] === null) {
            return false;
        }

        return $stay['endsOn'] > $found['endsOn'];
    }

    private function nextDay(string $day): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d') !== $day) {
            throw new InvalidArgumentException('a booth date is YYYY-MM-DD');
        }

        return $date->modify('+1 day')->format('Y-m-d');
    }

    private function requireDate(string $value): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new InvalidArgumentException('today is YYYY-MM-DD');
        }
    }

    private function day(mixed $value): string
    {
        $day = $this->optionalDay($value);
        if ($day === null) {
            throw new InvalidArgumentException('a booth date is YYYY-MM-DD');
        }

        return $day;
    }

    private function optionalDay(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            throw new InvalidArgumentException('a booth date is YYYY-MM-DD');
        }

        return substr($value, 0, 10);
    }

    private function text(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException('a booth field is missing');
        }

        return $value;
    }
}
