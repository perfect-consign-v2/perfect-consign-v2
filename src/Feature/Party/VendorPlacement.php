<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

/**
 * Which booth assignments are current, upcoming, or finished on a given day.
 */
final class VendorPlacement
{
    /**
     * @param list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string, rent_minor: ?int}> $assignments
     */
    public function status(array $assignments, string $today): string
    {
        if ($this->openCovering($assignments, $today)) {
            return 'active';
        }
        if ($this->covering($assignments, $today) !== []) {
            return 'ending';
        }
        if ($this->upcoming($assignments, $today) !== []) {
            return 'incoming';
        }

        return 'unassigned';
    }

    /**
     * @param list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string, rent_minor: ?int}> $assignments
     * @return list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string, rent_minor: ?int}>
     */
    public function covering(array $assignments, string $today): array
    {
        return $this->select($assignments, $today, 'covering');
    }

    /**
     * @param list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string, rent_minor: ?int}> $assignments
     * @return list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string, rent_minor: ?int}>
     */
    public function upcoming(array $assignments, string $today): array
    {
        return $this->select($assignments, $today, 'upcoming');
    }

    /**
     * @param list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string, rent_minor: ?int}> $assignments
     * @return list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string, rent_minor: ?int}>
     */
    public function past(array $assignments, string $today): array
    {
        return $this->select($assignments, $today, 'past');
    }

    /**
     * @param list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string, rent_minor: ?int}> $assignments
     */
    public function since(array $assignments): string
    {
        $since = '';
        foreach ($assignments as $assignment) {
            if ($since === '' || $assignment['starts_on'] < $since) {
                $since = $assignment['starts_on'];
            }
        }

        return $since;
    }

    /**
     * @param list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string, rent_minor: ?int}> $current
     * @param list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string, rent_minor: ?int}> $upcoming
     * @return list<array{id: string, code: string, occupancy: string}>
     */
    public function held(array $current, array $upcoming): array
    {
        $booths = [];
        foreach ($current as $assignment) {
            $booths[$assignment['booth_id']] = [
                'id' => $assignment['booth_id'],
                'code' => $assignment['booth_code'],
                'occupancy' => $assignment['ends_on'] === '' ? 'occupied' : 'ending',
            ];
        }
        foreach ($upcoming as $assignment) {
            if (isset($booths[$assignment['booth_id']])) {
                continue;
            }
            $booths[$assignment['booth_id']] = [
                'id' => $assignment['booth_id'],
                'code' => $assignment['booth_code'],
                'occupancy' => 'incoming',
            ];
        }

        return array_values($booths);
    }

    /**
     * @param list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string, rent_minor: ?int}> $assignments
     */
    private function openCovering(array $assignments, string $today): bool
    {
        foreach ($this->covering($assignments, $today) as $assignment) {
            if ($assignment['ends_on'] === '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string, rent_minor: ?int}> $assignments
     * @return list<array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string, rent_minor: ?int}>
     */
    private function select(array $assignments, string $today, string $kind): array
    {
        $matched = [];
        foreach ($assignments as $assignment) {
            if ($this->keeps($assignment, $today, $kind)) {
                $matched[] = $assignment;
            }
        }

        return $matched;
    }

    /**
     * @param array{id: string, booth_id: string, booth_code: string, starts_on: string, ends_on: string, rent_minor: ?int} $assignment
     */
    private function keeps(array $assignment, string $today, string $kind): bool
    {
        $ends = $assignment['ends_on'];
        $covers = $assignment['starts_on'] <= $today && ($ends === '' || $ends >= $today);
        if ($kind === 'covering') {
            return $covers;
        }
        if ($kind === 'upcoming') {
            return $assignment['starts_on'] > $today;
        }

        return $ends !== '' && $ends < $today;
    }
}
