<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Shared\Database\Statements;

/**
 * Holder, open rent, and the rows the kernel will not let the list delete.
 */
final class BoothListStore
{
    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rates(): array
    {
        return $this->statements->rows(
            'SELECT booth.id AS booth_id, booth.code, rate.amount_minor
             FROM booth_rent_rates AS rate
             JOIN booths AS booth ON booth.id = rate.booth_id
             ORDER BY booth.code'
        );
    }

    /**
     * @return array{
     *   stays: list<array<string, mixed>>,
     *   open_rent: list<array<string, mixed>>,
     *   blocked: list<array<string, mixed>>
     * }
     */
    public function facts(): array
    {
        return [
            'stays' => $this->statements->rows(
                'SELECT assignment.booth_id, assignment.vendor_id, vendor.name AS vendor_name,
                        vendor.code AS vendor_code, assignment.starts_on, assignment.ends_on
                 FROM booth_assignments AS assignment
                 JOIN vendors AS vendor ON vendor.id = assignment.vendor_id
                 ORDER BY assignment.starts_on'
            ),
            'open_rent' => $this->statements->rows(
                "SELECT line.subledger_ref AS booth_id,
                        sum(line.debit_minor - line.credit_minor) AS open_minor
                 FROM journal_lines AS line
                 JOIN chart_roles AS role ON role.account_id = line.account_id
                 WHERE role.role = 'booth_rent_receivable'
                   AND line.subledger_type = 'booth'
                 GROUP BY line.subledger_ref
                 HAVING sum(line.debit_minor - line.credit_minor) > 0"
            ),
            'blocked' => $this->statements->rows(
                "SELECT id FROM booths
                 WHERE EXISTS (
                     SELECT 1 FROM booth_assignments WHERE booth_id = booths.id
                 ) OR EXISTS (
                     SELECT 1 FROM booth_rent_rates WHERE booth_id = booths.id
                 ) OR EXISTS (
                     SELECT 1 FROM journal_lines
                     WHERE subledger_type = 'booth'
                       AND subledger_ref = booths.id::text
                 )"
            ),
        ];
    }
}
