<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Report;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class BooksReport
{
    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * @return list<array{code: string, account_type: string, balance_minor: int}>
     */
    public function rows(): array
    {
        $listed = [];
        foreach ($this->statements->rows(
            'SELECT code, account_type, balance_minor FROM account_balances ORDER BY code'
        ) as $row) {
            $listed[] = [
                'code' => RowValue::string($row['code'] ?? null),
                'account_type' => RowValue::string($row['account_type'] ?? null),
                'balance_minor' => RowValue::integer($row['balance_minor'] ?? null),
            ];
        }

        return $listed;
    }

    public function viewTotal(): int
    {
        return $this->total('SELECT COALESCE(sum(balance_minor), 0) AS total FROM account_balances');
    }

    public function lineTotal(): int
    {
        return $this->total(
            "SELECT COALESCE(sum(
                CASE account.normal_balance
                    WHEN 'debit' THEN line.debit_minor - line.credit_minor
                    ELSE line.credit_minor - line.debit_minor
                END
            ), 0) AS total
            FROM accounts AS account
            LEFT JOIN journal_lines AS line
              ON line.account_id = account.id AND line.book_id = account.book_id"
        );
    }

    private function total(string $sql): int
    {
        $rows = $this->statements->rows($sql);
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            return 0;
        }

        return RowValue::integer($row['total'] ?? null);
    }
}
