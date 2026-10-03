<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Payout;

use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\JournalSource;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\PostedLines;

final class PayoutSettler
{
    public function __construct(
        private readonly LedgerStore $ledger,
        private readonly PayoutStore $payouts,
        private readonly PayoutRunStore $runs,
        private readonly PayoutRunLines $lines,
    ) {
    }

    /**
     * @return list<string>
     */
    public function chargeMissing(string $vendorId, string $today): array
    {
        $posted = [];
        foreach ($this->payouts->missingRent($vendorId, $today) as $charge) {
            $posted[] = $this->ledger->post(new JournalDraft(
                postingKey: 'rent-charge:' . $charge['booth_id'] . ':' . $charge['month'],
                postingDate: $charge['posting_date'],
                source: new JournalSource('rent_charge', $charge['booth_id'] . ':' . $charge['month'], 'Rent charge'),
                lines: [
                    PostedLines::entry($this->ledger->roleAccount('booth_rent_receivable'), $charge['amount'], 0, 'booth', $charge['booth_id']),
                    PostedLines::entry($this->ledger->roleAccount('rent_revenue'), 0, $charge['amount'], null, null),
                ],
            ));
        }

        return $posted;
    }

    public function close(string $runId, string $today): void
    {
        $this->lines->guard($runId, false);
        foreach ($this->runs->pack($runId) as $row) {
            foreach ($this->chargeMissing($row['vendor_id'], $today) as $journalId) {
                $this->lines->link($runId, $journalId, 'rent_charge');
            }
        }
        $this->lines->materialize($runId);
        foreach ($this->runs->stored($runId) as $row) {
            if ($row['mode'] !== 'pay' || $row['settled'] <= 0) {
                continue;
            }
            $journalId = $this->settle($runId, $row['vendor_id'], $today);
            if ($journalId === '') {
                continue;
            }
            $this->lines->link($runId, $journalId, 'settlement');
            $this->lines->attachSettlement($runId, $row['vendor_id'], $journalId);
        }
        $this->lines->mark($runId, 'settled');
    }

    public function settle(string $runId, string $vendorId, string $postingDate): string
    {
        $open = $this->payouts->openPayout($vendorId);
        $applied = min($open['payable'], $open['rent']);
        if ($applied <= 0) {
            return '';
        }
        $lines = [
            PostedLines::entry($this->ledger->roleAccount('vendor_payable'), $applied, 0, 'vendor', $vendorId),
        ];
        $left = $applied;
        foreach ($open['booths'] as $booth) {
            $take = min($left, $booth['open_minor']);
            if ($take <= 0) {
                continue;
            }
            $lines[] = PostedLines::entry(
                $this->ledger->roleAccount('booth_rent_receivable'),
                0,
                $take,
                'booth',
                $booth['booth_id']
            );
            $left -= $take;
        }

        return $this->ledger->post(new JournalDraft(
            postingKey: 'settlement:' . $runId . ':' . $vendorId,
            postingDate: $postingDate,
            source: new JournalSource('settlement', $vendorId, 'Settlement'),
            lines: $lines,
        ));
    }
}
