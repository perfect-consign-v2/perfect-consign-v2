<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Feature\Payout\VendorClawback;

final class VendorBalances
{
    public function __construct(
        private readonly PayoutStore $payouts,
        private readonly VendorClawback $clawback,
        private readonly VendorYearPaid $paid,
    ) {
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
