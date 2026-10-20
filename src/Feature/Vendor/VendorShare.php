<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Vendor;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class VendorShare
{
    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * @return array{sold: int, take: int, owed: int, expired: int}
     */
    public function consignor(string $vendorId): array
    {
        $rows = $this->statements->bound(
            'SELECT COALESCE(sum(sale.price_minor * sale.quantity - sale.discount_minor), 0) AS sold,
                    COALESCE(sum(sale.commission_minor), 0) AS take,
                    COALESCE(sum(sale.price_minor * sale.quantity - sale.discount_minor - sale.commission_minor), 0) AS owed
             FROM sale_lines AS sale
             JOIN items AS item ON item.id = sale.item_id
             JOIN journals AS journal ON journal.id = sale.journal_id
             WHERE item.vendor_id = ?::uuid
               AND item.placement = ?
               AND journal.source_type = ?
               AND journal.is_reversal = false',
            [$vendorId, 'consigned', 'sale']
        );
        $expired = $this->statements->bound(
            'SELECT count(*)::int AS total
             FROM items
             WHERE vendor_id = ?::uuid AND placement = ? AND expires_on IS NOT NULL AND expires_on < (clock_timestamp() AT TIME ZONE (SELECT time_zone FROM mall))::date AND quantity > 0',
            [$vendorId, 'consigned']
        );
        $row = $rows[0] ?? [];

        return [
            'sold' => RowValue::integer($row['sold'] ?? 0),
            'take' => RowValue::integer($row['take'] ?? 0),
            'owed' => RowValue::integer($row['owed'] ?? 0),
            'expired' => RowValue::integer($expired[0]['total'] ?? 0),
        ];
    }

    /**
     * @return array{sold: int, owed: int, rent: int, settled: int}
     */
    public function dealer(string $vendorId): array
    {
        $sales = $this->statements->bound(
            'SELECT COALESCE(sum(sale.price_minor * sale.quantity - sale.discount_minor), 0) AS sold,
                    COALESCE(sum(sale.price_minor * sale.quantity - sale.discount_minor - sale.commission_minor), 0) AS owed
             FROM sale_lines AS sale
             JOIN items AS item ON item.id = sale.item_id
             JOIN journals AS journal ON journal.id = sale.journal_id
             WHERE item.vendor_id = ?::uuid
               AND item.placement = ?
               AND journal.source_type = ?
               AND journal.is_reversal = false',
            [$vendorId, 'booth', 'sale']
        );
        $rent = $this->statements->bound(
            "SELECT COALESCE(sum(line.debit_minor - line.credit_minor), 0) AS open
             FROM journal_lines AS line
             JOIN chart_roles AS role ON role.account_id = line.account_id
             JOIN booth_assignments AS assignment ON assignment.booth_id::text = line.subledger_ref
             WHERE role.role = 'booth_rent_receivable'
               AND assignment.vendor_id = ?::uuid",
            [$vendorId]
        );
        $row = $sales[0] ?? [];

        return [
            'sold' => RowValue::integer($row['sold'] ?? 0),
            'owed' => RowValue::integer($row['owed'] ?? 0),
            'rent' => RowValue::integer($rent[0]['open'] ?? 0),
            'settled' => 0,
        ];
    }
}
