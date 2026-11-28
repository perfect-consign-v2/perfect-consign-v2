<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Payout;

use PerfectConsign\Ledger\PostingFunctions;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

/**
 * What a vendor owes back after a return or void that came after their
 * payout. The database works out every amount and posts the entries; PHP
 * says which sale or which payout.
 */
final class VendorClawback
{
    public function __construct(
        private readonly PostingFunctions $postings,
        private readonly Statements $statements,
    ) {
    }

    public function openPayable(string $vendorId): int
    {
        return $this->read('SELECT GREATEST(stall_vendor_open_payable(?::uuid), 0) AS total', $vendorId);
    }

    public function openReceivable(string $vendorId): int
    {
        return $this->read('SELECT GREATEST(stall_vendor_open_clawback(?::uuid), 0) AS total', $vendorId);
    }

    /**
     * Before a sale is voided, what each vendor was paid out of it becomes
     * owed back. Returns how many vendors that was.
     */
    public function beforeVoid(string $saleId, string $postingDate): int
    {
        return $this->postings->postBatch(
            [$postingDate],
            'stall_clawback_reclass(?::uuid, ?::date)',
            [$saleId, $postingDate]
        );
    }

    /**
     * Takes what the vendor owes back out of this payout. Returns the entry,
     * or '' when there is nothing to take.
     */
    public function apply(string $vendorId, string $postingDate, string $runId): string
    {
        if ($this->read('SELECT stall_clawback_due(?::uuid) AS total', $vendorId) < 1) {
            return '';
        }

        return $this->postings->post(
            $postingDate,
            'stall_clawback_apply(?::uuid, ?, ?::date)',
            [$vendorId, $runId, $postingDate]
        );
    }

    private function read(string $sql, string $vendorId): int
    {
        $rows = $this->statements->bound($sql, [$vendorId]);

        return RowValue::integer($rows[0]['total'] ?? null);
    }
}
