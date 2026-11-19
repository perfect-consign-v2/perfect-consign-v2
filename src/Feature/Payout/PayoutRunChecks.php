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

    public function assignNumbers(string $runId, int $start): int
    {
        if ($start <= 0) {
            throw new DatabaseRefusal('Type the number on the first check.');
        }
        $written = 0;
        $this->statements->transaction(function () use ($runId, &$start, &$written): void {
            foreach ($this->waiting($runId) as $row) {
                $number = (string) $start;
                $updated = $this->statements->affected(
                    'UPDATE payout_run_lines
                     SET check_number = ?
                     WHERE run_id = ?::uuid AND vendor_id = ?::uuid AND check_number IS NULL',
                    [$number, $runId, $row['vendor_id']]
                );
                if ($updated !== 1) {
                    continue;
                }
                $this->statements->change(
                    'INSERT INTO register_checks (journal_id, check_number) VALUES (?::uuid, ?)',
                    [$row['journal_id'], $number]
                );
                $start++;
                $written++;
            }
        });

        return $written;
    }

    /**
     * @return list<array{vendor_id: string, journal_id: string}>
     */
    private function waiting(string $runId): array
    {
        $rows = $this->statements->bound(
            "SELECT line.vendor_id,
                    COALESCE(line.disbursement_journal_id, linked.journal_id) AS disbursement_journal_id
             FROM payout_run_lines AS line
             JOIN vendors AS vendor ON vendor.id = line.vendor_id
             LEFT JOIN LATERAL (
                 SELECT journal.id AS journal_id
                 FROM payout_run_journals AS link
                 JOIN journals AS journal ON journal.id = link.journal_id
                 WHERE link.run_id = line.run_id
                   AND link.role = 'disbursement'
                   AND NOT journal.is_reversal
                   AND journal.posting_key = 'disbursement:' || line.run_id::text || ':' || line.vendor_id::text
                   AND NOT EXISTS (
                       SELECT 1 FROM journals AS reversal
                       WHERE reversal.reverses_journal_id = journal.id
                   )
                 ORDER BY journal.journal_no
                 LIMIT 1
             ) AS linked ON true
             WHERE line.run_id = ?::uuid
               AND line.mode = 'pay'
               AND line.check_minor > 0
               AND line.check_number IS NULL
               AND COALESCE(line.disbursement_journal_id, linked.journal_id) IS NOT NULL
             ORDER BY vendor.code",
            [$runId]
        );
        $waiting = [];
        foreach ($rows as $row) {
            $waiting[] = [
                'vendor_id' => RowValue::string($row['vendor_id'] ?? null),
                'journal_id' => RowValue::string($row['disbursement_journal_id'] ?? null),
            ];
        }

        return $waiting;
    }
}
