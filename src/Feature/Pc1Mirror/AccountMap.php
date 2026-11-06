<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pc1Mirror;

/**
 * PC1 sense → PC2 chart role for opening journals.
 */
final class AccountMap
{
    public const VENDOR_PAYABLE = 'vendor_payable';

    public const BOOTH_RENT = 'booth_rent_receivable';

    public const SALES_TAX = 'sales_tax_payable';

    public const VENDOR_RECEIVABLE = 'vendor_receivable';

    public const CASH = 'cash';

    public const GIFT_CERTIFICATE = 'gift_certificate';

    public const GIFT_CARD = 'gift_card';

    public const STORE_CREDIT = 'store_credit';

    public const RETAINED = 'retained_earnings';

    /**
     * @return array<string, string>
     */
    public static function roles(): array
    {
        return [
            'vendor_payable' => self::VENDOR_PAYABLE,
            'booth_rent' => self::BOOTH_RENT,
            'sales_tax' => self::SALES_TAX,
            'vendor_receivable' => self::VENDOR_RECEIVABLE,
            'cash' => self::CASH,
            'gift_certificate' => self::GIFT_CERTIFICATE,
            'gift_card' => self::GIFT_CARD,
            'store_credit' => self::STORE_CREDIT,
            'retained_earnings' => self::RETAINED,
        ];
    }
}
