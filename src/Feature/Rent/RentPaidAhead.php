<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Rent;

use PerfectConsign\Ledger\PostingFunctions;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

/**
 * The nightly step for booth reservations: once a reserved stay's first rent
 * period arrives, the database charges that rent and pays it from the money
 * paid ahead. It runs before late fees, so a reservation is never late.
 */
final class RentPaidAhead
{
    public function __construct(
        private readonly Statements $statements,
        private readonly PostingFunctions $postings,
    ) {
    }

    public function apply(string $today): int
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) !== 1) {
            return 0;
        }
        $dates = [];
        foreach ($this->statements->bound("SELECT to_char(on_date, 'YYYY-MM-DD') AS on_date FROM stall_rent_paid_ahead_due(?::date)", [$today]) as $row) {
            $dates[] = RowValue::string($row['on_date'] ?? null);
        }
        if ($dates === []) {
            return 0;
        }

        return $this->postings->postBatch($dates, 'stall_recognize_rent_paid_in_advance(?::date)', [$today]);
    }
}
