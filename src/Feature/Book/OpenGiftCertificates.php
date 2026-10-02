<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Book;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class OpenGiftCertificates
{
    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * @return array{
     *     count: int,
     *     total: int,
     *     rows: list<array{number: string, journal_id: string, sold_on: string, issued: int, balance: int}>
     * }
     */
    public function outstanding(): array
    {
        $rows = [];
        $total = 0;
        foreach ($this->statements->rows(
            "SELECT stored.number,
                    journal.id AS journal_id,
                    to_char(journal.posting_date, 'YYYY-MM-DD') AS sold_on,
                    issued.amount AS issued,
                    COALESCE(sum(line.credit_minor - line.debit_minor), 0) AS balance
             FROM stored_values AS stored
             JOIN journals AS journal
               ON journal.source_type = 'gift_certificate_issue'
              AND journal.source_reference = stored.number
              AND journal.is_reversal = false
             JOIN (
                 SELECT journal_id, sum(credit_minor) AS amount
                 FROM journal_lines
                 WHERE subledger_type = 'gift_certificate'
                   AND credit_minor > 0
                 GROUP BY journal_id
             ) AS issued ON issued.journal_id = journal.id
             LEFT JOIN journal_lines AS line
               ON line.subledger_ref = stored.id::text
              AND line.subledger_type = 'gift_certificate'
             WHERE stored.kind = 'gift_certificate'
             GROUP BY stored.id, stored.number, journal.id, journal.posting_date, issued.amount
             HAVING COALESCE(sum(line.credit_minor - line.debit_minor), 0) > 0
             ORDER BY journal.posting_date, lower(stored.number)"
        ) as $row) {
            $balance = RowValue::integer($row['balance'] ?? null);
            $rows[] = [
                'number' => RowValue::string($row['number'] ?? null),
                'journal_id' => RowValue::string($row['journal_id'] ?? null),
                'sold_on' => RowValue::string($row['sold_on'] ?? null),
                'issued' => RowValue::integer($row['issued'] ?? null),
                'balance' => $balance,
            ];
            $total += $balance;
        }

        return [
            'count' => count($rows),
            'total' => $total,
            'rows' => $rows,
        ];
    }
}
