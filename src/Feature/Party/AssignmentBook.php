<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class AssignmentBook
{
    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function assignments(): array
    {
        return $this->statements->rows(
            'SELECT assignment.id, assignment.booth_id, assignment.vendor_id, booth.code AS booth_code,
                    vendor.code AS vendor_code, assignment.starts_on, assignment.ends_on
             FROM booth_assignments AS assignment
             JOIN booths AS booth ON booth.id = assignment.booth_id
             JOIN vendors AS vendor ON vendor.id = assignment.vendor_id
             ORDER BY assignment.starts_on, booth.code'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function vendorRows(string $asOf): array
    {
        return $this->vendorRowQuery($asOf, null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function vendorRowsFor(string $vendorId, string $asOf): array
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $vendorId) !== 1) {
            throw new \InvalidArgumentException('a vendor id is required');
        }

        return $this->vendorRowQuery($asOf, $vendorId);
    }

    /**
     * @return array<string, int>
     */
    public function askingRents(): array
    {
        $rates = [];
        foreach ($this->statements->rows(
            'SELECT booth_id::text AS booth_id, amount_minor FROM booth_rent_rates'
        ) as $row) {
            $rates[RowValue::string($row['booth_id'] ?? null)] = RowValue::integer($row['amount_minor'] ?? null);
        }

        return $rates;
    }

    /**
     * @return list<array{starts_on: string, amount_minor: int}>
     */
    public function rentHistory(string $assignmentId): array
    {
        $listed = [];
        foreach ($this->statements->bound(
            'SELECT starts_on::text AS starts_on, amount_minor
             FROM assignment_rent_rates
             WHERE assignment_id = ?::uuid
             ORDER BY starts_on',
            [$assignmentId]
        ) as $row) {
            $listed[] = [
                'starts_on' => RowValue::string($row['starts_on'] ?? null),
                'amount_minor' => RowValue::integer($row['amount_minor'] ?? null),
            ];
        }

        return $listed;
    }

    public function changeRent(string $assignmentId, string $startsOn, int $amountMinor): void
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $assignmentId) !== 1) {
            throw new \InvalidArgumentException('Choose a stay.');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $startsOn) !== 1) {
            throw new \InvalidArgumentException('Enter an effective date.');
        }
        if ($amountMinor < 1) {
            throw new \InvalidArgumentException('Rent must be greater than zero.');
        }
        $stay = $this->statements->bound(
            "SELECT to_char(starts_on, 'YYYY-MM-DD') AS starts_on,
                    to_char(ends_on, 'YYYY-MM-DD') AS ends_on
             FROM booth_assignments
             WHERE id = ?::uuid",
            [$assignmentId]
        );
        if ($stay === []) {
            throw new \InvalidArgumentException('Choose a stay.');
        }
        $stayStart = RowValue::string($stay[0]['starts_on'] ?? null);
        $stayEnd = RowValue::optionalString($stay[0]['ends_on'] ?? null);
        if ($startsOn < $stayStart) {
            throw new \InvalidArgumentException('The effective date cannot be before the stay starts.');
        }
        if ($stayEnd !== null && $startsOn > $stayEnd) {
            throw new \InvalidArgumentException('The effective date cannot be after the stay ends.');
        }
        $this->statements->change(
            'INSERT INTO assignment_rent_rates (assignment_id, starts_on, amount_minor)
             VALUES (?::uuid, ?::date, ?)',
            [$assignmentId, $startsOn, $amountMinor]
        );
    }

    public function addAssignment(
        string $boothId,
        string $vendorId,
        string $startsOn,
        string $endsOn,
        ?int $rentMinor = null,
    ): void {
        $this->statements->transaction(function () use ($boothId, $vendorId, $startsOn, $endsOn, $rentMinor): void {
            $sql = $endsOn === ''
                ? 'INSERT INTO booth_assignments (booth_id, vendor_id, starts_on) VALUES (?::uuid, ?::uuid, ?::date) RETURNING id'
                : 'INSERT INTO booth_assignments (booth_id, vendor_id, starts_on, ends_on) VALUES (?::uuid, ?::uuid, ?::date, ?::date) RETURNING id';
            $params = $endsOn === ''
                ? [$boothId, $vendorId, $startsOn]
                : [$boothId, $vendorId, $startsOn, $endsOn];
            $rows = $this->statements->bound($sql, $params);
            $id = $rows[0]['id'] ?? null;
            if (!is_string($id) || $id === '') {
                throw new \InvalidArgumentException('that assignment was not created');
            }
            if ($rentMinor === null) {
                throw new \InvalidArgumentException('that booth has no asking rent');
            }
            $this->statements->change(
                'INSERT INTO assignment_rent (assignment_id, amount_minor) VALUES (?::uuid, ?)',
                [$id, $rentMinor]
            );
        });
    }

    public function askingRent(string $boothId): ?int
    {
        $rows = $this->statements->bound(
            'SELECT amount_minor FROM booth_rent_rates WHERE booth_id = ?::uuid',
            [$boothId]
        );
        $amount = $rows[0]['amount_minor'] ?? null;
        if ($amount === null || $amount === '') {
            return null;
        }

        return RowValue::integer($amount);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function vendorRowQuery(string $asOf, ?string $vendorId): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) !== 1) {
            throw new \InvalidArgumentException('a vendor day is YYYY-MM-DD');
        }
        $sql = 'SELECT vendor.id AS vendor_id, vendor.code AS vendor_code, vendor.name AS vendor_name,
                       vendor.first_name, vendor.last_name, vendor.phone, vendor.email, vendor.address, vendor.active,
                       assignment.id AS assignment_id, assignment.booth_id, booth.code AS booth_code,
                       assignment.starts_on, assignment.ends_on,
                       (
                           SELECT rate.amount_minor
                           FROM assignment_rent_rates AS rate
                           WHERE rate.assignment_id = assignment.id
                             AND rate.starts_on <= ?::date
                           ORDER BY rate.starts_on DESC
                           LIMIT 1
                       ) AS rent_minor
                FROM vendors AS vendor
                LEFT JOIN booth_assignments AS assignment ON assignment.vendor_id = vendor.id
                LEFT JOIN booths AS booth ON booth.id = assignment.booth_id';
        $params = [$asOf];
        if ($vendorId !== null) {
            $sql .= ' WHERE vendor.id = ?::uuid';
            $params[] = $vendorId;
        }
        $sql .= ' ORDER BY vendor.name, vendor.code, assignment.starts_on';

        return $this->statements->bound($sql, $params);
    }

    public function endAssignment(string $id, string $endsOn): void
    {
        $this->statements->change(
            'UPDATE booth_assignments SET ends_on = ?::date WHERE id = ?::uuid',
            [$endsOn, $id]
        );
    }

    public function setAskingRent(string $boothId, int $amount): void
    {
        $this->statements->change(
            'INSERT INTO booth_rent_rates (booth_id, amount_minor) VALUES (?::uuid, ?)
             ON CONFLICT (booth_id) DO UPDATE SET amount_minor = EXCLUDED.amount_minor',
            [$boothId, $amount]
        );
    }
}
