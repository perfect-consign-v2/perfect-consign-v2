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
     * The ticket's lines with the register's figures. The database works out
     * every amount (stall_ticket_quote); PHP only shows them.
     *
     * @return list<array{line_no: int, item_id: string, code: string, description: string, vendor: string, vendor_id: string, placement: string, cost: int, price: int, quantity: int, commission: int, commission_bps: int, tax: int, tax_exempt: bool, discount: int, store_discount: int, offer_discount: int, offer_name: string, line_discount: int, line_discount_kind: string, line_discount_value: string}>
     */
    public function lines(string $ticketId): array
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
            'SELECT quote.line_no, quote.item_id, quote.price_minor, quote.quantity, quote.commission_bps,
                    quote.discount_minor, quote.store_discount_minor, quote.commission_minor, quote.tax_minor,
                    quote.tax_exempt::text AS tax_exempt,
                    line.line_discount_minor, line.offer_discount_minor, COALESCE(offer.name, \'\') AS offer_name,
                    line.line_discount_kind, line.line_discount_value,
                    item.code, item.description, item.vendor_id, item.placement, item.cost_minor,
                    vendor.name AS vendor_name
             FROM stall_ticket_quote(?::uuid) AS quote
             JOIN ticket_lines AS line ON line.ticket_id = ?::uuid AND line.line_no = quote.line_no
             JOIN items AS item ON item.id = quote.item_id
             LEFT JOIN vendors AS vendor ON vendor.id = item.vendor_id
             LEFT JOIN offers AS offer ON offer.id = line.offer_id
             ORDER BY quote.line_no',
            [$ticketId, $ticketId]
        );
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = $this->shownLine($row);
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

    public function recheck(string $ticketId): void
    {
        $this->statements->change(
            'UPDATE tickets SET discount_kind = discount_kind WHERE id = ?::uuid AND status = ? AND discount_kind IS NOT NULL',
            [$ticketId, 'open']
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array{line_no: int, item_id: string, code: string, description: string, vendor: string, vendor_id: string, placement: string, cost: int, price: int, quantity: int, commission: int, commission_bps: int, tax: int, tax_exempt: bool, discount: int, store_discount: int, offer_discount: int, offer_name: string, line_discount: int, line_discount_kind: string, line_discount_value: string}
     */
    private function shownLine(array $row): array
    {
        $kind = RowValue::optionalString($row['line_discount_kind'] ?? null) ?? '';
        $stored = $row['line_discount_value'] ?? null;
        $raw = $stored === null ? 0 : RowValue::integer($stored);
        $exempt = RowValue::string($row['tax_exempt'] ?? null);
        if ($exempt !== 'true' && $exempt !== 'false') {
            throw new DatabaseRefusal('the register quote is missing');
        }

        return [
            'line_no' => RowValue::integer($row['line_no'] ?? null),
            'item_id' => RowValue::string($row['item_id'] ?? null),
            'code' => RowValue::string($row['code'] ?? null),
            'description' => RowValue::string($row['description'] ?? null),
            'vendor' => RowValue::string($row['vendor_name'] ?? null),
            'vendor_id' => RowValue::string($row['vendor_id'] ?? null),
            'placement' => is_string($row['placement'] ?? null) ? $row['placement'] : 'consigned',
            'cost' => isset($row['cost_minor']) ? RowValue::integer($row['cost_minor']) : 0,
            'price' => RowValue::integer($row['price_minor'] ?? null),
            'quantity' => RowValue::integer($row['quantity'] ?? null),
            'commission' => RowValue::integer($row['commission_minor'] ?? null),
            'commission_bps' => RowValue::integer($row['commission_bps'] ?? null),
            'tax' => RowValue::integer($row['tax_minor'] ?? null),
            'tax_exempt' => $exempt === 'true',
            'discount' => RowValue::integer($row['discount_minor'] ?? null),
            'store_discount' => RowValue::integer($row['store_discount_minor'] ?? null),
            'offer_discount' => RowValue::integer($row['offer_discount_minor'] ?? null),
            'offer_name' => RowValue::string($row['offer_name'] ?? null),
            'line_discount' => RowValue::integer($row['line_discount_minor'] ?? null),
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
