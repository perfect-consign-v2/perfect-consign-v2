<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Close;

use PDO;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class CloseStore
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Statements $statements,
    ) {
    }

    /**
     * @return list<array{account_id: string, net_minor: int}>
     */
    public function periodMovement(string $periodId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT account.id AS account_id, sum(line.credit_minor - line.debit_minor) AS net_minor
             FROM journal_lines AS line
             JOIN journals AS posted ON posted.id = line.journal_id
             JOIN accounts AS account ON account.id = line.account_id
             WHERE posted.period_id = ?::uuid
               AND account.account_type IN ('revenue', 'expense')
             GROUP BY account.id
             HAVING sum(line.credit_minor - line.debit_minor) <> 0"
        );
        $statement->execute([$periodId]);
        $rows = [];
        foreach ($this->statements->all($statement) as $row) {
            $rows[] = [
                'account_id' => RowValue::string($row['account_id'] ?? null),
                'net_minor' => RowValue::integer($row['net_minor'] ?? null),
            ];
        }

        return $rows;
    }

    public function closeQuiet(string $periodId): void
    {
        $this->statements->change('SELECT stall_close_quiet_period(?::uuid)', [$periodId]);
    }
}
