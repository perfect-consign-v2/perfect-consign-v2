<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Payout;

use PerfectConsign\Bootstrap\JournalAudit;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\JournalSource;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\PostingFunctions;
use PerfectConsign\Ledger\PostedLines;

final class PayoutSettler
{
    public function __construct(
        private readonly LedgerStore $ledger,
        private readonly PostingFunctions $postings,
        private readonly PayoutStore $payouts,
        private readonly PayoutRunStore $runs,
        private readonly PayoutRunLines $lines,
        private readonly JournalAudit $audit,
        private readonly VendorClawback $clawback,
    ) {
    }

    /**
     * @return list<string>
     */
    public function chargeMissing(string $runId, string $vendorId): array
    {
        $posted = [];
        foreach ($this->payouts->missingCharges($runId, $vendorId) as $charge) {
            $posted[] = $this->postings->post(
                $charge['posting_date'],
                'stall_post_rent_charge(?::uuid, ?::date, ?::date)',
                [$charge['assignment_id'], $charge['month_start'], $charge['posting_date']],
            );
        }

        return $posted;
    }

    public function close(string $runId, string $today): void
    {
        $this->lines->guard($runId, false);
        foreach ($this->runs->pack($runId) as $row) {
            foreach ($this->chargeMissing($runId, $row['vendor_id']) as $journalId) {
                $this->lines->link($runId, $journalId, 'rent_charge');
            }
            $applied = $this->clawback->apply($row['vendor_id'], $today, $runId);
            if ($applied !== '') {
                $this->lines->link($runId, $applied, 'clawback_apply');
            }
        }
        $this->lines->materialize($runId);
        foreach ($this->runs->stored($runId) as $row) {
            if ($row['mode'] !== 'pay' || $row['settled'] <= 0) {
                continue;
            }
            $journalId = $this->settle($runId, $row['vendor_id'], $today, $row['settled']);
            if ($journalId === '') {
                continue;
            }
            $this->lines->link($runId, $journalId, 'settlement');
            $this->lines->attachSettlement($runId, $row['vendor_id'], $journalId);
        }
        $this->lines->mark($runId, 'settled');
    }

    public function settle(string $runId, string $vendorId, string $postingDate, int $applied): string
    {
        if ($applied <= 0) {
            return '';
        }
        $lines = [
            PostedLines::entry($this->ledger->roleAccount('vendor_payable'), $applied, 0, 'vendor', $vendorId),
        ];
        $placed = 0;
        foreach ($this->payouts->stayShares($vendorId, $applied) as $share) {
            $lines[] = PostedLines::entry(
                $this->ledger->roleAccount('booth_rent_receivable'),
                0,
                $share['amount_minor'],
                'assignment',
                $share['assignment_id']
            );
            $placed += $share['amount_minor'];
        }
        if ($placed !== $applied) {
            throw new DatabaseRefusal('the settlement does not match the open booth rent');
        }

        return $this->ledger->post(new JournalDraft(
            postingKey: 'settlement:' . $runId . ':' . $vendorId,
            postingDate: $postingDate,
            source: new JournalSource('settlement', $vendorId, 'Settlement'),
            lines: $lines,
            beforeCommit: $this->audit->on('payout.settle'),
        ));
    }
}
