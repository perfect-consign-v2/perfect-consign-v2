<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Payout;

use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class PayoutSingle
{
    public function __construct(
        private readonly Statements $statements,
        private readonly PayoutStore $payouts,
        private readonly PayoutSettler $settler,
        private readonly PayoutDisburser $disburser,
        private readonly PayoutRunLines $lines,
        private readonly PayoutReverser $reverser,
    ) {
    }

    public function pay(string $vendorId, string $postingDate): string
    {
        $journalId = '';
        $this->statements->transaction(function () use ($vendorId, $postingDate, &$journalId): void {
            $quoted = $this->payouts->quotedRun($vendorId, $postingDate);
            if ($quoted === null || ($quoted['settled'] <= 0 && $quoted['check'] <= 0)) {
                throw new DatabaseRefusal('a payout clears a positive open payable or open rent receivable');
            }
            $runId = $this->beginSingle($postingDate);
            foreach ($this->settler->chargeMissing($quoted['run_id'], $vendorId) as $rentId) {
                $this->lines->link($runId, $rentId, 'rent_charge');
            }
            $this->lines->vendorLine($runId, $vendorId);
            $journalId = $this->settler->settle($runId, $vendorId, $postingDate, $quoted['settled']);
            if ($journalId !== '') {
                $this->lines->link($runId, $journalId, 'settlement');
            }
            $this->lines->mark($runId, 'settled');
            $paid = $this->disburser->disburse($runId, $vendorId, $postingDate, $quoted['check'], null);
            if ($paid !== '') {
                $this->lines->link($runId, $paid, 'disbursement');
                $this->lines->mark($runId, 'paid');
                $journalId = $paid;
            }
        });

        return $journalId;
    }

    public function undo(string $runId, string $postingDate, string $actor, string $reason): string
    {
        if (trim($actor) === '' || trim($reason) === '') {
            throw new DatabaseRefusal('a payout reversal needs the operator and a reason');
        }

        return $this->reverser->undo($runId, $postingDate, trim($actor), trim($reason));
    }

    public function openSingle(string $vendorId): string
    {
        $rows = $this->statements->bound(
            "SELECT run.id
             FROM payout_runs AS run
             JOIN payout_run_lines AS line ON line.run_id = run.id
             WHERE line.vendor_id = ?::uuid
               AND run.kind = 'single'
               AND run.reverses_run_id IS NULL
               AND run.status IN ('settled', 'paid')
             ORDER BY run.run_no DESC
             LIMIT 1",
            [$vendorId]
        );

        return RowValue::optionalString($rows[0]['id'] ?? null) ?? '';
    }

    /**
     * @return array{by: string, reason: string, at: string}|null
     */
    public function lastUndone(string $vendorId): ?array
    {
        $rows = $this->statements->bound(
            "SELECT reversal.reversed_by, reversal.reversal_reason, run.reversed_at
             FROM payout_runs AS run
             JOIN payout_run_lines AS line ON line.run_id = run.id AND line.vendor_id = ?::uuid
             JOIN payout_runs AS reversal ON reversal.reverses_run_id = run.id
             WHERE run.kind = 'single'
               AND run.reverses_run_id IS NULL
               AND run.status = 'reversed'
               AND run.run_no = (
                   SELECT max(latest.run_no)
                   FROM payout_runs AS latest
                   JOIN payout_run_lines AS latest_line
                     ON latest_line.run_id = latest.id AND latest_line.vendor_id = ?::uuid
                   WHERE latest.kind = 'single'
                     AND latest.reverses_run_id IS NULL
               )
             ORDER BY reversal.run_no DESC
             LIMIT 1",
            [$vendorId, $vendorId]
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            return null;
        }
        $by = RowValue::optionalString($row['reversed_by'] ?? null);
        $reason = RowValue::optionalString($row['reversal_reason'] ?? null);
        $at = $row['reversed_at'] ?? null;
        if ($by === null || $reason === null || !is_string($at) || $at === '') {
            return null;
        }

        return ['by' => $by, 'reason' => $reason, 'at' => $at];
    }

    public function beginSingle(string $postingDate): string
    {
        $rows = $this->statements->bound(
            "INSERT INTO payout_runs (
                book_id, kind, cadence, sales_from, sales_to, tender, status, posting_date
             )
             SELECT id, 'single', 'ad_hoc', ?::date, ?::date, 'check', 'draft', ?::date
             FROM ledger_books
             WHERE is_default
             RETURNING id",
            [$postingDate, $postingDate, $postingDate]
        );

        return RowValue::string($rows[0]['id'] ?? null);
    }
}
