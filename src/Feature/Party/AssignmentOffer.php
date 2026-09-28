<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\Money;
use PerfectConsign\Shared\Http\View;

final class AssignmentOffer
{
    public function __construct(
        private readonly PartyStore $parties,
        private readonly MallSettings $mall,
    ) {
    }

    public function override(string $boothId, string $startsOn, string $typed): ?int
    {
        if ($typed === '') {
            return null;
        }
        $amount = Money::parseMinor($typed);
        $default = $this->parties->getBoothRateMinor($boothId, $startsOn);
        if ($default !== null && $amount === $default) {
            return null;
        }

        return $amount;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function booths(): array
    {
        $today = $this->mall->today();
        $booths = [];
        foreach ($this->parties->booths() as $booth) {
            $id = $booth['id'] ?? null;
            $minor = is_string($id) ? $this->parties->getBoothRateMinor($id, $today) : null;
            $booth['rent'] = $minor === null ? '' : View::money($minor);
            $booths[] = $booth;
        }

        return $booths;
    }
}
