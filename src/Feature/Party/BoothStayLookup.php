<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

/**
 * Who holds a booth today, and who is next in line.
 */
final class BoothStayLookup
{
    private bool $hideHolder = false;

    private ?string $endOverride = null;

    public function hideHolder(bool $hide): void
    {
        $this->hideHolder = $hide;
    }

    public function overrideHolderEnd(?string $end): void
    {
        $this->endOverride = $end;
    }

    /**
     * @param list<array{boothId: string, vendorId: string, vendorName: string, vendorCode: string, startsOn: string, endsOn: ?string}> $stays
     * @return list<array{boothId: string, vendorName: string, startsOn: string, endsOn: ?string}>
     */
    public function forMap(array $stays): array
    {
        $mapped = [];
        foreach ($stays as $stay) {
            $mapped[] = [
                'boothId' => $stay['boothId'],
                'vendorName' => $stay['vendorName'],
                'startsOn' => $stay['startsOn'],
                'endsOn' => $stay['endsOn'],
            ];
        }

        return $mapped;
    }

    /**
     * @param list<array{boothId: string, vendorId: string, vendorName: string, vendorCode: string, startsOn: string, endsOn: ?string}> $stays
     * @return array{boothId: string, vendorId: string, vendorName: string, vendorCode: string, startsOn: string, endsOn: ?string}|null
     */
    public function person(array $stays, string $boothId, string $today, string $status, ?string $vendorName): ?array
    {
        if ($this->hideHolder || $vendorName === null) {
            return null;
        }
        $found = $status === 'incoming'
            ? $this->incoming($stays, $boothId, $today)
            : $this->covering($stays, $boothId, $today, $vendorName);
        if ($found !== null && $this->endOverride !== null) {
            $found['endsOn'] = $this->endOverride === '' ? null : $this->endOverride;
        }

        return $found;
    }

    /**
     * @param list<array{boothId: string, vendorId: string, vendorName: string, vendorCode: string, startsOn: string, endsOn: ?string}> $stays
     * @return array{boothId: string, vendorId: string, vendorName: string, vendorCode: string, startsOn: string, endsOn: ?string}|null
     */
    public function incoming(array $stays, string $boothId, string $today): ?array
    {
        $next = null;
        foreach ($stays as $stay) {
            if ($stay['boothId'] !== $boothId || $stay['startsOn'] <= $today) {
                continue;
            }
            if ($next === null || $stay['startsOn'] < $next['startsOn']) {
                $next = $stay;
            }
        }

        return $next;
    }

    /**
     * @param list<array{boothId: string, vendorId: string, vendorName: string, vendorCode: string, startsOn: string, endsOn: ?string}> $stays
     * @return array{boothId: string, vendorId: string, vendorName: string, vendorCode: string, startsOn: string, endsOn: ?string}|null
     */
    private function covering(array $stays, string $boothId, string $today, string $vendorName): ?array
    {
        $covering = null;
        foreach ($stays as $stay) {
            if ($stay['boothId'] !== $boothId || $stay['vendorName'] !== $vendorName) {
                continue;
            }
            $open = $stay['endsOn'] === null || $stay['endsOn'] >= $today;
            if ($stay['startsOn'] <= $today && $open) {
                $covering = $stay;
            }
        }

        return $covering;
    }
}
