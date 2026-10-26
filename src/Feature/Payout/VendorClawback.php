<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Payout;

use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\JournalSource;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\PostedLines;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class VendorClawback
{
    public function __construct(
        private readonly LedgerStore $ledger,
        private readonly Statements $statements,
    ) {
    }

    public function openPayable(string $vendorId): int
    {
        return $this->balance('vendor_payable', $vendorId, true);
    }

    public function openReceivable(string $vendorId): int
    {
        return $this->balance('vendor_receivable', $vendorId, false);
    }

    public function reclass(string $vendorId, int $amount, string $postingDate, string $saleId): string
    {
        if ($amount < 1) {
            return '';
        }

        return $this->ledger->post(new JournalDraft(
            postingKey: 'clawback_reclass:' . $saleId . ':' . $vendorId,
            postingDate: $postingDate,
            source: new JournalSource('clawback_reclass', $saleId . ':' . $vendorId, 'Clawback after payout'),
            lines: [
                PostedLines::entry($this->ledger->roleAccount('vendor_receivable'), $amount, 0, 'vendor', $vendorId),
                PostedLines::entry($this->ledger->roleAccount('vendor_payable'), 0, $amount, 'vendor', $vendorId),
            ],
        ));
    }

    public function apply(string $vendorId, string $postingDate, string $runId): string
    {
        $amount = min($this->openPayable($vendorId), $this->openReceivable($vendorId));
        if ($amount < 1) {
            return '';
        }

        return $this->ledger->post(new JournalDraft(
            postingKey: 'clawback_apply:' . $runId . ':' . $vendorId,
            postingDate: $postingDate,
            source: new JournalSource('clawback_apply', $runId . ':' . $vendorId, 'Apply clawback'),
            lines: [
                PostedLines::entry($this->ledger->roleAccount('vendor_payable'), $amount, 0, 'vendor', $vendorId),
                PostedLines::entry($this->ledger->roleAccount('vendor_receivable'), 0, $amount, 'vendor', $vendorId),
            ],
        ));
    }

    private function balance(string $role, string $vendorId, bool $creditMinusDebit): int
    {
        $expression = $creditMinusDebit
            ? 'sum(line.credit_minor - line.debit_minor)'
            : 'sum(line.debit_minor - line.credit_minor)';
        $rows = $this->statements->bound(
            'SELECT COALESCE(' . $expression . ', 0) AS total
             FROM journal_lines AS line
             JOIN chart_roles AS role ON role.account_id = line.account_id
             WHERE role.role = ?
               AND line.subledger_type = \'vendor\'
               AND line.subledger_ref = ?',
            [$role, $vendorId]
        );

        return max(0, RowValue::integer($rows[0]['total'] ?? null));
    }
}
