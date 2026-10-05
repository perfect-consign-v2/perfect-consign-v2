<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

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
                throw new \InvalidArgumentException('that booth has no asking rent');
            }

            return $asking;
        }

        return Money::parseMinor($typed);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function booths(): array
    {
        $booths = [];
        foreach ($this->parties->booths() as $booth) {
            $id = $booth['id'] ?? null;
            $minor = is_string($id) ? $this->parties->askingRent($id) : null;
            $booth['rent'] = $minor === null ? '' : View::money($minor);
            $booths[] = $booth;
        }

        return $booths;
    }
}
