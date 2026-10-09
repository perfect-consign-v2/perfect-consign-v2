<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class VendorMove
{
    public function __construct(private readonly Statements $statements)
    {
    }

    public function move(string $vendorId, string $assignmentId, string $boothId, string $endsOn, int $rentMinor): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $endsOn) !== 1) {
            throw new \InvalidArgumentException('Enter the move-out date.');
        }
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $boothId) !== 1) {
            throw new \InvalidArgumentException('Choose the next booth.');
        }
        $next = (new \DateTimeImmutable($endsOn))->modify('+1 day')->format('Y-m-d');
        $this->statements->transaction(function () use ($vendorId, $assignmentId, $boothId, $endsOn, $next, $rentMinor): void {
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
            $id = RowValue::string($created[0]['id'] ?? null);
            $this->statements->change(
                'INSERT INTO assignment_rent (assignment_id, amount_minor) VALUES (?::uuid, ?)',
                [$id, $rentMinor]
            );
        });
    }
}
