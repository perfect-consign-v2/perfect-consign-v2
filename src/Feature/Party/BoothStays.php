<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Bootstrap\JournalAudit;

/**
 * Starts, ends and re-rates a vendor's booth stays, each inside an audited transaction.
 */
final class BoothStays
{
    public function __construct(
        private readonly AssignmentBook $stays,
        private readonly JournalAudit $audit,
    ) {
    }

    public function addStay(
        string $boothId,
        string $vendorId,
        string $startsOn,
        string $endsOn,
        ?int $rentMinor,
    ): string {
        $id = '';
        $this->audit->within(function () use ($boothId, $vendorId, $startsOn, $endsOn, $rentMinor, &$id): void {
            $id = $this->stays->addAssignment($boothId, $vendorId, $startsOn, $endsOn, $rentMinor);
            $this->audit->record('assignment.create', 'assignment', $id);
        });

        return $id;
    }

    public function setStayEnd(string $id, string $endsOn): void
    {
        $this->audit->within(function () use ($id, $endsOn): void {
            $this->stays->endAssignment($id, $endsOn);
            $this->audit->record('assignment.end', 'assignment', $id);
        });
    }

    public function setStayRent(string $id, string $startsOn, int $amount): void
    {
        $this->audit->within(function () use ($id, $startsOn, $amount): void {
            $this->stays->changeRent($id, $startsOn, $amount);
            $this->audit->record('assignment.rent', 'assignment', $id);
        });
    }
}
