<?php

declare(strict_types=1);

namespace PerfectConsign\Ledger;

final class SourceLabel
{
    /** @var array<string, string> */
    private const NAMES = [
        'sale' => 'Sale',
        'item_return' => 'Return',
        'rent_charge' => 'Rent',
        'rent_payment' => 'Rent payment',
        'late_fee' => 'Late fee',
        'check_disbursement' => 'Check',
        'settlement' => 'Settlement',
        'opening' => 'Opening amount',
        'adjustment' => 'Adjustment',
        'tax_payment' => 'Sales tax',
        'period_close' => 'Close the year',
        'cash_drop' => 'Cash drop',
        'register_variance' => 'Drawer difference',
        'booth_correction' => 'Wrong booth',
        'store_purchase' => 'Store purchase',
        'store_use' => 'Store use',
        'missed_sale' => 'Missed sale',
        'vendor_memo' => 'Vendor memo',
        'layaway_deposit' => 'Layaway deposit',
        'layaway_refund' => 'Layaway refund',
        'gift_certificate_issue' => 'Gift certificate',
        'gift_card_issue' => 'Gift card',
        'store_credit_issue' => 'Store credit',
    ];

    public static function text(string $source): string
    {
        if (isset(self::NAMES[$source])) {
            return self::NAMES[$source];
        }
        $words = str_replace('_', ' ', trim($source));
        if ($words === '') {
            return 'Entry';
        }

        return ucwords($words);
    }
}
