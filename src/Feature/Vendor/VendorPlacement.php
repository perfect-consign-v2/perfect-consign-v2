<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Vendor;

use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Shared\Database\Statements;

/** Where a vendor may place a piece, and whether a booth stay is open today. */
final class VendorPlacement
{
    public function __construct(
        private readonly Statements $statements,
        private readonly MallSettings $mall,
    ) {
    }

    /**
     * @return list<string>
     */
    public function choices(): array
    {
        $choices = [];
        if ($this->mall->isRentalsEnabled()) {
            $choices[] = 'booth';
        }
        if ($this->mall->isConsignmentEnabled()) {
            $choices[] = 'consigned';
        }

        return $choices;
    }

    public function forCreate(string $posted, string $vendorId, string $today): string
    {
        $choices = $this->choices();
        if ($choices === []) {
            throw new \InvalidArgumentException('This store is not taking vendor items.');
        }
        $chosen = count($choices) === 1 ? $choices[0] : $posted;
        if (!in_array($chosen, ['booth', 'consigned'], true) || !in_array($chosen, $choices, true)) {
            throw new \InvalidArgumentException('Choose where this piece sits.');
        }
        if ($chosen === 'booth' && !$this->stayOpen($vendorId, $today)) {
            throw new \InvalidArgumentException('a booth piece needs an open assignment');
        }

        return $chosen;
    }

    public function forEdit(string $stored): string
    {
        if ($stored !== 'booth' && $stored !== 'consigned') {
            throw new \InvalidArgumentException('Choose where this piece sits.');
        }

        return $stored;
    }

    private function stayOpen(string $vendorId, string $today): bool
    {
        $rows = $this->statements->bound(
            'SELECT id FROM booth_assignments
             WHERE vendor_id = ?::uuid
               AND starts_on <= ?::date
               AND (ends_on IS NULL OR ends_on >= ?::date)
             LIMIT 1',
            [$vendorId, $today, $today]
        );

        return $rows !== [];
    }
}
