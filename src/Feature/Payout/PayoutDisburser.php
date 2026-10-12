<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Payout;

use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\JournalSource;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\PostedLines;

final class PayoutDisburser
{
    public function __construct(
        private readonly LedgerStore $ledger,
        private readonly PayoutStore $payouts,
        private readonly PayoutRunStore $runs,
        private readonly PayoutRunLines $lines,
    ) {
    }

    public function pay(string $runId, string $postingDate, ?string $sessionId = null): void
    {
        $run = $this->runs->run($runId);
        if ($run['status'] !== 'settled') {
            throw new DatabaseRefusal('a check is written only while the run is settled');
        }
        $this->lines->guard($runId, true);
        foreach ($this->lines->checks($runId) as $line) {
            $open = $this->payouts->openPayout($line['vendor_id'])['payable'];
            if ($open !== $line['check_minor']) {
                throw new DatabaseRefusal('the open payable changed after review');
            }
            $source = $this->source($run['tender']);
            $session = $run['tender'] === 'drawer_cash' ? $sessionId : null;
            $journalId = $this->disburse(
                $runId,
                $line['vendor_id'],
                $postingDate,
                $line['check_minor'],
                null,
                $source['source'],
                $source['role'],
                $session,
            );
            $this->lines->link($runId, $journalId, 'disbursement');
            $this->lines->attachDisbursement($runId, $line['vendor_id'], $journalId, null);
        }
        $this->lines->mark($runId, 'paid');
    }

    public function numberChecks(string $runId, int $start): void
    {
        $run = $this->runs->run($runId);
        if ($run['status'] !== 'paid' || $run['tender'] !== 'check') {
            throw new DatabaseRefusal('Those checks are not ready to print.');
        }
        $this->lines->assignNumbers($runId, $start);
    }

    /**
     * @return array{source: string, role: string}
     */
    private function source(string $tender): array
    {
        if ($tender === 'drawer_cash') {
            return ['source' => 'cash_disbursement', 'role' => 'cash'];
        }
        if ($tender === 'safe_cash') {
            return ['source' => 'safe_disbursement', 'role' => 'cash_in_safe'];
        }

        return ['source' => 'check_disbursement', 'role' => 'bank'];
    }

    public function disburse(
        string $runId,
        string $vendorId,
        string $postingDate,
        int $amount,
        ?string $checkNumber,
        string $source = 'check_disbursement',
        string $creditRole = 'bank',
        ?string $sessionId = null,
    ): string {
        if ($amount <= 0) {
            return '';
        }

        return $this->ledger->post(new JournalDraft(
            postingKey: 'disbursement:' . $runId . ':' . $vendorId,
            postingDate: $postingDate,
            source: new JournalSource($source, $vendorId, 'Check'),
            lines: [
                PostedLines::entry($this->ledger->roleAccount('vendor_payable'), $amount, 0, 'vendor', $vendorId),
                PostedLines::entry($this->ledger->roleAccount($creditRole), 0, $amount, null, null),
            ],
            checkNumber: $checkNumber,
            sessionId: $sessionId,
        ));
    }
}
