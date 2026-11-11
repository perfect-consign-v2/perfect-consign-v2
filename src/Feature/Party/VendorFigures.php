<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Party;

/** Applies payout quote amounts onto a vendor display row. */
final class VendorFigures
{
    /**
     * @param array<string, mixed> $vendor
     * @param array{run_id: string, payable: int, rent: int, check: int, owes: int, settled: int, rent_from_sales: bool}|null $quoted
     * @return array<string, mixed>
     */
    public function apply(array $vendor, ?array $quoted): array
    {
        $vendor['payable_minor'] = 0;
        $vendor['rent_minor'] = 0;
        $vendor['check_minor'] = 0;
        $vendor['owes_minor'] = 0;
        $vendor['settled_minor'] = 0;
        $vendor['clawback_minor'] = 0;
        $vendor['rent_from_sales'] = false;
        if ($quoted === null) {
            return $vendor;
        }
        $vendor['payable_minor'] = $quoted['payable'];
        $vendor['rent_minor'] = $quoted['rent'];
        $vendor['check_minor'] = $quoted['check'];
        $vendor['owes_minor'] = $quoted['owes'];
        $vendor['settled_minor'] = $quoted['settled'];
        $vendor['rent_from_sales'] = $quoted['rent_from_sales'];

        return $vendor;
    }
}
