<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Bootstrap\JournalAudit;

/**
 * Re-rates a vendor's booth stay inside an audited transaction. Starting,
 * ending and cancelling a stay go through BoothBooking.
 */
final class BoothStays
{
    public function __construct(
        private readonly AssignmentBook $stays,
        private readonly JournalAudit $audit,
    ) {
    }

    public function setStayRent(string $id, string $startsOn, int $amount): void
    {
        $this->audit->within(function () use ($id, $startsOn, $amount): void {
            $this->stays->changeRent($id, $startsOn, $amount);
            $this->audit->record('assignment.rent', 'assignment', $id);
        });
    }
}
