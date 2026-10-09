<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Month;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class MonthStore
{
    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * @return array{sales: int, tax: int, rent: int, checks: int}
     */
    public function figures(string $from, string $to): array
    {
        return [
            'sales' => $this->sum(
                "SELECT COALESCE(sum(journal.debit_total_minor), 0) AS total
                 FROM journals AS journal
                 WHERE journal.source_type = 'sale'
                   AND journal.is_reversal = false
                   AND journal.posting_date BETWEEN ?::date AND ?::date
                   AND NOT EXISTS (
                       SELECT 1 FROM journals AS reversal
                       WHERE reversal.reverses_journal_id = journal.id
                   )",
                [$from, $to]
            ),
            'tax' => $this->sum(
                "SELECT COALESCE(sum(line.credit_minor - line.debit_minor), 0) AS total
                 FROM journal_lines AS line
                 JOIN journals AS journal ON journal.id = line.journal_id
                 JOIN chart_roles AS role ON role.account_id = line.account_id
                 WHERE role.role = 'sales_tax_payable'
                   AND journal.source_type = 'sale'
                   AND journal.is_reversal = false
                   AND journal.posting_date BETWEEN ?::date AND ?::date
                   AND NOT EXISTS (
                       SELECT 1 FROM journals AS reversal
                       WHERE reversal.reverses_journal_id = journal.id
                   )",
                [$from, $to]
            ),
            'rent' => $this->sum(
                "SELECT COALESCE(sum(line.debit_minor - line.credit_minor), 0) AS total
                 FROM journal_lines AS line
                 JOIN chart_roles AS role ON role.account_id = line.account_id
                 WHERE role.role = 'booth_rent_receivable'",
                []
            ),
            'checks' => $this->sum(
                "SELECT COALESCE(sum(journal.debit_total_minor), 0) AS total
                 FROM journals AS journal
                 WHERE journal.source_type = 'check_disbursement'
                   AND journal.is_reversal = false
                   AND journal.posting_date BETWEEN ?::date AND ?::date
                   AND NOT EXISTS (
                       SELECT 1 FROM journals AS reversal
                       WHERE reversal.reverses_journal_id = journal.id
                   )",
                [$from, $to]
            ),
        ];
    }

    /**
     * @param list<mixed> $params
     */
    private function sum(string $sql, array $params): int
    {
        $rows = $params === [] ? $this->statements->rows($sql) : $this->statements->bound($sql, $params);

        return RowValue::integer($rows[0]['total'] ?? 0);
    }
}
