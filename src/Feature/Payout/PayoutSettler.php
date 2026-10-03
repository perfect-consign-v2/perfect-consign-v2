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
    ) {
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
