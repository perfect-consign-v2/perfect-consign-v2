<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Booth;

use PerfectConsign\Ledger\Money;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

/**
 * The stays a person can choose on the booth extras page.
 */
final class BoothStayChoices
{
    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * Stays that hold a booth today or later, and stays that still owe rent.
     *
     * @return list<array{id: string, label: string}>
     */
    public function current(string $today): array
    {
        return $this->labelled($this->statements->bound(
            "SELECT stay.id::text AS id, booth.code, vendor.name AS vendor_name
             FROM booth_assignments AS stay
             JOIN booths AS booth ON booth.id = stay.booth_id
             JOIN vendors AS vendor ON vendor.id = stay.vendor_id
             WHERE stay.ends_on IS NULL
                OR stay.ends_on >= ?::date
                OR stall_assignment_open_rent(stay.id) > 0
             ORDER BY booth.code, stay.starts_on",
            [$today]
        ), null);
    }

    /**
     * Stays the store still holds a deposit for, with the amount held.
     *
     * @return list<array{id: string, label: string}>
     */
    public function depositsHeld(): array
    {
        return $this->labelled($this->statements->rows(
            "SELECT stay.id::text AS id, booth.code, vendor.name AS vendor_name,
                    sum(line.credit_minor - line.debit_minor) AS held_minor
             FROM journal_lines AS line
             JOIN chart_roles AS role ON role.account_id = line.account_id AND role.role = 'booth_deposit_held'
             JOIN booth_assignments AS stay ON stay.id::text = line.subledger_ref AND line.subledger_type = 'assignment'
             JOIN booths AS booth ON booth.id = stay.booth_id
             JOIN vendors AS vendor ON vendor.id = stay.vendor_id
             GROUP BY stay.id, booth.code, vendor.name
             HAVING sum(line.credit_minor - line.debit_minor) > 0
             ORDER BY booth.code"
        ), 'held_minor');
    }

    public function depositsEnabled(): bool
    {
        $rows = $this->statements->rows('SELECT booth_deposits_enabled FROM mall');

        return in_array($rows[0]['booth_deposits_enabled'] ?? false, [true, 1, '1', 't', 'true'], true);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{id: string, label: string}>
     */
    private function labelled(array $rows, ?string $held): array
    {
        $choices = [];
        foreach ($rows as $row) {
            $label = 'Booth ' . RowValue::string($row['code'] ?? null) . ', ' . RowValue::string($row['vendor_name'] ?? null);
            if ($held !== null) {
                $minor = RowValue::integer($row[$held] ?? null);
                $label .= ' (holding ' . Money::format($minor) . ')';
            }
            $choices[] = ['id' => RowValue::string($row['id'] ?? null), 'label' => $label];
        }

        return $choices;
    }
}
