<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Payout;

use PDO;
use PerfectConsign\Shared\Database\Statements;

final class PayoutStore
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Statements $statements,
    ) {
    }

    /**
     * @return array{payable: int, rent: int, booths: list<array{booth_id: string, open_minor: int}>}
     */
    public function openPayout(string $vendorId): array
    {
        $payable = $this->pdo->prepare(
            "SELECT COALESCE(sum(line.credit_minor - line.debit_minor), 0)
             FROM journal_lines AS line
             JOIN chart_roles AS role ON role.account_id = line.account_id
             WHERE role.role = 'vendor_payable'
               AND line.subledger_type = 'vendor'
               AND line.subledger_ref = ?"
        );
        $payable->execute([$vendorId]);
        $rent = $this->pdo->prepare(
            "SELECT line.subledger_ref AS booth_id, sum(line.debit_minor - line.credit_minor) AS open_minor
             FROM journal_lines AS line
             JOIN journals AS posted ON posted.id = line.journal_id
             JOIN chart_roles AS role ON role.account_id = line.account_id
             WHERE role.role = 'booth_rent_receivable'
               AND line.subledger_type = 'booth'
               AND EXISTS (
                   SELECT 1 FROM booth_assignments AS assignment
                   WHERE assignment.vendor_id = ?::uuid
                     AND assignment.booth_id = line.subledger_ref::uuid
                     AND assignment.assignment_range @> posted.posting_date
               )
             GROUP BY line.subledger_ref
             HAVING sum(line.debit_minor - line.credit_minor) > 0"
        );
        $rent->execute([$vendorId]);
        $booths = [];
        $rentTotal = 0;
        foreach ($this->statements->all($rent) as $row) {
            $open = (int) $row['open_minor'];
            $booths[] = ['booth_id' => (string) $row['booth_id'], 'open_minor' => $open];
            $rentTotal += $open;
        }

        return [
            'payable' => (int) $payable->fetchColumn(),
            'rent' => $rentTotal,
            'booths' => $booths,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function statement(string $vendorId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT posting_date, journal_no, source_type, posting_key, role, debit_minor, credit_minor
             FROM vendor_statement
             WHERE vendor_id = ?::uuid
             ORDER BY posting_date, journal_no, line_no'
        );
        $statement->execute([$vendorId]);

        return $this->statements->all($statement);
    }
}
