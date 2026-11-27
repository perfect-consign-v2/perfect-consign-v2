<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Vendor;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class VendorFigures
{
    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * @return array{payable: int, rent: int, clawback: int}
     */
    public function numbers(string $vendorId): array
    {
        return [
            'payable' => $this->sum(
                "SELECT COALESCE(sum(line.credit_minor - line.debit_minor), 0) AS total
                 FROM journal_lines AS line
                 JOIN chart_roles AS role ON role.account_id = line.account_id
                 WHERE role.role = 'vendor_payable'
                   AND line.subledger_type = 'vendor'
                   AND line.subledger_ref = ?",
                [$vendorId]
            ),
            'rent' => $this->sum(
                'SELECT stall_vendor_open_rent(?::uuid) AS total',
                [$vendorId]
            ),
            'clawback' => $this->sum(
                "SELECT COALESCE(sum(line.debit_minor - line.credit_minor), 0) AS total
                 FROM journal_lines AS line
                 JOIN chart_roles AS role ON role.account_id = line.account_id
                 WHERE role.role = 'vendor_receivable'
                   AND line.subledger_type = 'vendor'
                   AND line.subledger_ref = ?",
                [$vendorId]
            ),
        ];
    }

    /**
     * @param list<mixed> $params
     */
    private function sum(string $sql, array $params): int
    {
        $rows = $this->statements->bound($sql, $params);
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            return 0;
        }

        return RowValue::integer($row['total'] ?? 0);
    }
}
