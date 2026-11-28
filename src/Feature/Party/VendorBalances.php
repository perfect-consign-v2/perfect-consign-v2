<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Feature\Payout\VendorClawback;

/**
 * @phpstan-import-type RentMonth from RentHistory
 */
final class VendorBalances
{
    public function __construct(
        private readonly PayoutStore $payouts,
        private readonly VendorClawback $clawback,
        private readonly VendorYearPaid $paid,
        private readonly RentHistory $history,
    ) {
    }

    /**
     * @return array{months: list<RentMonth>, more: bool}
     */
    public function rentHistory(string $vendorId, bool $all): array
    {
        return $this->history->forVendor($vendorId, $all);
    }

    /**
     * @return array{run_id: string, payable: int, rent: int, check: int, owes: int, settled: int, rent_from_sales: bool}|null
     */
    public function quote(string $vendorId, string $today): ?array
    {
        return $this->payouts->quote($vendorId, $today);
    }

    public function payable(string $vendorId): int
    {
        return $this->payouts->openPayout($vendorId)['payable'];
    }

    public function clawback(string $vendorId): int
    {
        return $this->clawback->openReceivable($vendorId);
    }

    public function yearPaid(string $vendorId): int
    {
        return $this->paid->yearPaid($vendorId);
    }
}
