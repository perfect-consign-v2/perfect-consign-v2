<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Tax;

use PDO;

final class TaxStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function openTax(): int
    {
        $value = $this->pdo->query(
            "SELECT COALESCE(sum(line.credit_minor - line.debit_minor), 0)
             FROM journal_lines AS line
             JOIN chart_roles AS role ON role.account_id = line.account_id
             WHERE role.role = 'sales_tax_payable'"
        );
        if ($value === false) {
            return 0;
        }

        return (int) $value->fetchColumn();
    }
}
