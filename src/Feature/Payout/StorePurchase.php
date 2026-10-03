<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Payout;

use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\JournalSource;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\PostedLines;

final class StorePurchase
{
    public function __construct(private readonly LedgerStore $ledger)
    {
    }

    public function record(string $vendorId, string $postingDate, int $amount, string $reference): string
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('a store purchase is a positive amount');
        }
        $trimmed = trim($reference);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('a store purchase needs a reference');
        }

        return $this->ledger->post(new JournalDraft(
            postingKey: 'store-purchase:' . $trimmed,
            postingDate: $postingDate,
            source: new JournalSource('store_purchase', $vendorId, 'Store purchase'),
            lines: [
                PostedLines::entry($this->ledger->roleAccount('store_purchases'), $amount, 0, null, null),
                PostedLines::entry($this->ledger->roleAccount('vendor_payable'), 0, $amount, 'vendor', $vendorId),
            ],
        ));
    }
}
