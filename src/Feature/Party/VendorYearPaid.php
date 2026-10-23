<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class VendorYearPaid
{
    public function __construct(private readonly Statements $statements)
    {
    }

    public function yearPaid(string $id): int
    {
        $rows = $this->statements->bound(
            "SELECT COALESCE(SUM(line.debit_minor), 0)::bigint AS paid
             FROM journal_lines AS line
             JOIN journals AS journal ON journal.id = line.journal_id
             JOIN chart_roles AS role ON role.account_id = line.account_id AND role.role = 'vendor_payable'
             WHERE line.subledger_type = 'vendor'
               AND line.subledger_ref = ?
               AND journal.source_type IN ('check_disbursement', 'cash_disbursement', 'safe_disbursement')
               AND NOT journal.is_reversal
               AND journal.posting_date >= date_trunc('year', (clock_timestamp() AT TIME ZONE (SELECT time_zone FROM mall)))::date",
            [$id]
        );

        return RowValue::integer($rows[0]['paid'] ?? null);
    }
}
