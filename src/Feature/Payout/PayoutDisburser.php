<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Payout;

use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\JournalSource;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\PostedLines;

final class PayoutDisburser
{
    public function __construct(private readonly LedgerStore $ledger)
    {
    }

    public function disburse(
        string $runId,
        string $vendorId,
        string $postingDate,
        int $amount,
        ?string $checkNumber,
    ): string {
        if ($amount <= 0) {
            return '';
        }

        return $this->ledger->post(new JournalDraft(
            postingKey: 'disbursement:' . $runId . ':' . $vendorId,
            postingDate: $postingDate,
            source: new JournalSource('check_disbursement', $vendorId, 'Check'),
            lines: [
                PostedLines::entry($this->ledger->roleAccount('vendor_payable'), $amount, 0, 'vendor', $vendorId),
                PostedLines::entry($this->ledger->roleAccount('bank'), 0, $amount, null, null),
            ],
            checkNumber: $checkNumber,
        ));
    }
}
