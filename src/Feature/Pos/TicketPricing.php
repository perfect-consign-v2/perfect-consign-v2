<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class TicketPricing
{
    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * @param array{kind: ?string, value: ?int, reason: string, waiver: string} $adjustment
     * @return list<array{line_no: int, item_id: string, code: string, description: string, vendor: string, vendor_id: string, price: int, quantity: int, commission: int, commission_bps: int, tax: int, tax_exempt: bool, discount: int, store_discount: int, offer_discount: int, offer_name: string, line_discount: int, line_discount_kind: string, line_discount_value: string}>
     */
    public function lines(string $ticketId, int $taxBps, array $adjustment): array
    {
        $this->statements->change(
            'UPDATE ticket_lines AS line
             SET quantity = line.quantity
             FROM tickets AS ticket
             WHERE line.ticket_id = ticket.id
               AND ticket.id = ?::uuid
               AND ticket.status = ?',
            [$ticketId, 'open']
        );
        $rows = $this->statements->bound(
            'SELECT line.line_no, line.item_id, line.price_minor, line.quantity, line.line_discount_minor,
                    line.offer_discount_minor, line.store_discount_minor, COALESCE(offer.name, \'\') AS offer_name,
                    item.code, item.description, item.vendor_id, item.placement, item.cost_minor,
                    vendor.name AS vendor_name, vendor.tax_exempt,
                    line.line_discount_kind, line.line_discount_value, line.line_discount_reason,
                    CASE
                        WHEN item.placement = \'store\' THEN 0
                        ELSE stall_commission_for(item.id, line.price_minor, (clock_timestamp() AT TIME ZONE mall.time_zone)::date)
                    END AS commission_bps
             FROM ticket_lines AS line
             JOIN items AS item ON item.id = line.item_id
             LEFT JOIN vendors AS vendor ON vendor.id = item.vendor_id
             CROSS JOIN mall
             LEFT JOIN offers AS offer ON offer.id = line.offer_id
             WHERE line.ticket_id = ?::uuid
             ORDER BY line.line_no',
            [$ticketId]
        );
        $event = $this->mallSale();
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = $this->quote(
                $row,
                $taxBps,
                $this->share($ticketId, RowValue::string($row['item_id'] ?? null), $adjustment),
                $adjustment['waiver'],
                $event['percent_bps'],
                $event['absorbed_by']
            );
        }

        return $lines;
    }

    /**
     * @return array{kind: ?string, value: ?int, reason: string, waiver: string}
     */
    public function adjustment(string $ticketId): array
    {
        $rows = $this->statements->bound(
            'SELECT discount_kind, discount_value, discount_reason, tax_waiver_reason FROM tickets WHERE id = ?::uuid',
            [$ticketId]
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            return $this->emptyAdjustment();
        }
        $kind = $row['discount_kind'] ?? null;
        $value = $row['discount_value'] ?? null;

        return [
            'kind' => is_string($kind) && $kind !== '' ? $kind : null,
            'value' => $value === null ? null : RowValue::integer($value),
            'reason' => is_string($row['discount_reason'] ?? null) ? $row['discount_reason'] : '',
            'waiver' => is_string($row['tax_waiver_reason'] ?? null) ? $row['tax_waiver_reason'] : '',
        ];
    }

    /**
     * @return array{kind: null, value: null, reason: string, waiver: string}
     */
    public function emptyAdjustment(): array
    {
        return ['kind' => null, 'value' => null, 'reason' => '', 'waiver' => ''];
    }

    public function discount(string $ticketId, ?string $kind, ?int $value, ?string $reason): void
    {
        if ($kind === null || $value === null || $value === 0) {
            $this->clear($ticketId);
            return;
        }
        $updated = $this->statements->affected(
            'UPDATE tickets SET discount_kind = ?, discount_value = ?, discount_reason = ? WHERE id = ?::uuid AND status = ?',
            [$kind, $value, $reason, $ticketId, 'open']
        );
        if ($updated !== 1) {
            throw new DatabaseRefusal('there is no open ticket');
        }
    }

    public function waive(string $ticketId, ?string $reason): void
    {
        $updated = $this->statements->affected(
            'UPDATE tickets SET tax_waiver_reason = ? WHERE id = ?::uuid AND status = ?',
            [$reason, $ticketId, 'open']
        );
        if ($updated !== 1) {
            throw new DatabaseRefusal('there is no open ticket');
        }
    }

    /**
     * @param array{kind: ?string, value: ?int, reason: string, waiver: string} $adjustment
     */
    private function share(string $ticketId, string $itemId, array $adjustment): int
    {
        $rows = $this->statements->bound(
            'SELECT stall_line_discount(?::uuid, ?::uuid, ?, ?) AS share',
            [$ticketId, $itemId, $adjustment['kind'], $adjustment['value']]
        );

        return RowValue::integer($rows[0]['share'] ?? null);
    }

    public function recheck(string $ticketId): void
    {
        $this->statements->change(
            'UPDATE tickets SET discount_kind = discount_kind WHERE id = ?::uuid AND status = ? AND discount_kind IS NOT NULL',
            [$ticketId, 'open']
        );
    }

    /**
     * @return array{percent_bps: int, absorbed_by: string}
     */
    private function mallSale(): array
    {
        $rows = $this->statements->rows(
            "SELECT percent_bps, absorbed_by
             FROM mall_sales
             WHERE starts_on <= (clock_timestamp() AT TIME ZONE (SELECT time_zone FROM mall))::date
               AND ends_on >= (clock_timestamp() AT TIME ZONE (SELECT time_zone FROM mall))::date
             ORDER BY starts_on DESC
             LIMIT 1"
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            return ['percent_bps' => 0, 'absorbed_by' => 'vendor'];
        }

        return [
            'percent_bps' => RowValue::integer($row['percent_bps'] ?? null),
            'absorbed_by' => RowValue::string($row['absorbed_by'] ?? null) === 'mall' ? 'mall' : 'vendor',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{line_no: int, item_id: string, code: string, description: string, vendor: string, vendor_id: string, placement: string, cost: int, price: int, quantity: int, commission: int, commission_bps: int, tax: int, tax_exempt: bool, discount: int, store_discount: int, offer_discount: int, offer_name: string, line_discount: int, line_discount_kind: string, line_discount_value: string}
     */
    private function quote(array $row, int $taxBps, int $discount, string $waiver, int $mallBps, string $absorbed): array
    {
        $price = RowValue::integer($row['price_minor'] ?? null);
        $quantity = RowValue::integer($row['quantity'] ?? null);
        $extended = $price * $quantity;
        $lineDiscount = RowValue::integer($row['line_discount_minor'] ?? null);
        $offerDiscount = RowValue::integer($row['offer_discount_minor'] ?? null);
        $storeDiscount = RowValue::integer($row['store_discount_minor'] ?? null);
        $mallOff = $mallBps > 0 ? intdiv($extended * $mallBps + 5000, 10000) : 0;
        $taken = $lineDiscount + $discount + $offerDiscount + ($absorbed === 'mall' ? 0 : $mallOff);
        $storeDiscount += $absorbed === 'mall' ? $mallOff : 0;
        $exempt = self::flag($row['tax_exempt'] ?? null);
        $kind = RowValue::optionalString($row['line_discount_kind'] ?? null) ?? '';
        $stored = $row['line_discount_value'] ?? null;
        $raw = $stored === null ? 0 : RowValue::integer($stored);

        return [
            'line_no' => RowValue::integer($row['line_no'] ?? null),
            'item_id' => RowValue::string($row['item_id'] ?? null),
            'code' => RowValue::string($row['code'] ?? null),
            'description' => RowValue::string($row['description'] ?? null),
            'vendor' => RowValue::string($row['vendor_name'] ?? null),
            'vendor_id' => RowValue::string($row['vendor_id'] ?? null),
            'placement' => is_string($row['placement'] ?? null) ? $row['placement'] : 'consigned',
            'cost' => isset($row['cost_minor']) ? RowValue::integer($row['cost_minor']) : 0,
            'price' => $price,
            'quantity' => $quantity,
            'commission' => TicketQuote::halfUp($extended - $taken, RowValue::integer($row['commission_bps'] ?? null)),
            'commission_bps' => RowValue::integer($row['commission_bps'] ?? null),
            'tax' => $exempt || $waiver !== '' ? 0 : TicketQuote::tax($extended, $taken + $storeDiscount, $taxBps),
            'tax_exempt' => $exempt,
            'discount' => $taken,
            'store_discount' => $storeDiscount,
            'offer_discount' => $offerDiscount,
            'offer_name' => RowValue::string($row['offer_name'] ?? null),
            'line_discount' => $lineDiscount,
            'line_discount_kind' => $kind,
            'line_discount_value' => self::shown($kind, $raw),
        ];
    }

    private function clear(string $ticketId): void
    {
        $this->statements->change(
            'UPDATE tickets SET discount_kind = NULL, discount_value = NULL, discount_reason = NULL WHERE id = ?::uuid AND status = ?',
            [$ticketId, 'open']
        );
    }

    private static function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }

    private static function shown(string $kind, int $raw): string
    {
        if ($raw < 1) {
            return '';
        }
        if ($kind === 'percent') {
            $whole = intdiv($raw, 100);
            $frac = $raw % 100;

            return $frac === 0 ? (string) $whole : $whole . '.' . str_pad((string) $frac, 2, '0', STR_PAD_LEFT);
        }

        return number_format($raw / 100, 2, '.', '');
    }
}
