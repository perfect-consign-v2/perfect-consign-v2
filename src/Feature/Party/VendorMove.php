<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Bootstrap\JournalAudit;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class VendorMove
{
    public function __construct(
        private readonly Statements $statements,
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

    public function move(string $vendorId, string $assignmentId, string $boothId, string $endsOn, int $rentMinor): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $endsOn) !== 1) {
            throw new \InvalidArgumentException('Enter the move-out date.');
        }
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $boothId) !== 1) {
            throw new \InvalidArgumentException('Choose the next booth.');
        }
        $next = (new \DateTimeImmutable($endsOn))->modify('+1 day')->format('Y-m-d');
        $createdId = '';
        $this->audit->within(function () use ($vendorId, $assignmentId, $boothId, $endsOn, $next, $rentMinor, &$createdId): void {
            $rows = $this->statements->bound(
                'SELECT id FROM booth_assignments
                 WHERE id = ?::uuid AND vendor_id = ?::uuid AND ends_on IS NULL',
                [$assignmentId, $vendorId]
            );
            if ($rows === []) {
                throw new \InvalidArgumentException('That booth stay is already finished.');
            }
            $this->statements->change(
                'UPDATE booth_assignments SET ends_on = ?::date WHERE id = ?::uuid AND vendor_id = ?::uuid AND ends_on IS NULL',
                [$endsOn, $assignmentId, $vendorId]
            );
            $created = $this->statements->bound(
                'INSERT INTO booth_assignments (booth_id, vendor_id, starts_on) VALUES (?::uuid, ?::uuid, ?::date) RETURNING id',
                [$boothId, $vendorId, $next]
            );
            $createdId = RowValue::string($created[0]['id'] ?? null);
            $this->statements->change(
                'INSERT INTO assignment_rent (assignment_id, amount_minor) VALUES (?::uuid, ?)',
                [$createdId, $rentMinor]
            );
            $this->audit->record('assignment.move', 'assignment', $createdId);
        });

        return $createdId;
    }
}
