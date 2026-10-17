<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Payout;

use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class PayoutRunChecks
{
    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * @return list<array{vendor_id: string, check_minor: int, mode: string}>
     */
    public function checks(string $runId): array
    {
        $rows = $this->statements->bound(
            "SELECT vendor_id, check_minor, mode FROM payout_run_lines
             WHERE run_id = ?::uuid AND mode = 'pay' AND check_minor > 0
             ORDER BY vendor_id",
            [$runId]
        );
        $checks = [];
        foreach ($rows as $row) {
            $checks[] = [
                'vendor_id' => RowValue::string($row['vendor_id'] ?? null),
                'check_minor' => RowValue::integer($row['check_minor'] ?? null),
                'mode' => RowValue::string($row['mode'] ?? null),
            ];
        }

        return $checks;
    }

    public function assignNumbers(string $runId, int $start): void
    {
        if ($start <= 0) {
            throw new DatabaseRefusal('Type the number on the first check.');
        }
        $this->statements->transaction(function () use ($runId, $start): void {
            $rows = $this->statements->bound(
                "SELECT line.vendor_id, line.disbursement_journal_id
                 FROM payout_run_lines AS line
                 JOIN vendors AS vendor ON vendor.id = line.vendor_id
                 WHERE line.run_id = ?::uuid AND line.mode = 'pay' AND line.check_minor > 0
                   AND line.check_number IS NULL
                   AND line.disbursement_journal_id IS NOT NULL
                 ORDER BY vendor.code",
                [$runId]
            );
            foreach ($rows as $row) {
                $vendorId = RowValue::string($row['vendor_id'] ?? null);
                $journalId = RowValue::string($row['disbursement_journal_id'] ?? null);
                $number = (string) $start;
                $written = $this->statements->affected(
                    'UPDATE payout_run_lines
                     SET check_number = ?
                     WHERE run_id = ?::uuid AND vendor_id = ?::uuid AND check_number IS NULL',
                    [$number, $runId, $vendorId]
                );
                if ($written !== 1) {
                    continue;
                }
                $this->statements->change(
                    'INSERT INTO register_checks (journal_id, check_number) VALUES (?::uuid, ?)',
                    [$journalId, $number]
                );
                $start++;
            }
        });
    }
}
