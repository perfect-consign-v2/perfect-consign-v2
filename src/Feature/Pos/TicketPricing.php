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
     * @return list<array{line_no: int, item_id: string, code: string, description: string, vendor: string, vendor_id: string, price: int, commission: int, tax: int, discount: int}>
     */
    public function lines(string $ticketId, int $taxBps, array $adjustment): array
    {
        $rows = $this->statements->bound(
            'SELECT line.line_no, line.item_id, line.price_minor, item.code, item.description, item.vendor_id,
                    vendor.name AS vendor_name, vendor.tax_exempt,
                    COALESCE(item.commission_bps, vendor.commission_bps, mall.commission_bps) AS commission_bps
             FROM ticket_lines AS line
             JOIN items AS item ON item.id = line.item_id
             JOIN vendors AS vendor ON vendor.id = item.vendor_id
             CROSS JOIN mall
             WHERE line.ticket_id = ?::uuid
             ORDER BY line.line_no',
            [$ticketId]
        );
        $prices = [];
        foreach ($rows as $row) {
            $prices[] = RowValue::integer($row['price_minor'] ?? null);
        }
        $shares = TicketQuote::shares($prices, $adjustment['kind'], $adjustment['value']);
        $lines = [];
        foreach ($rows as $index => $row) {
            $lines[] = $this->quote($row, $taxBps, $shares[$index] ?? 0, $adjustment['waiver']);
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
     * @return array{line_no: int, item_id: string, code: string, description: string, vendor: string, vendor_id: string, price: int, commission: int, tax: int, discount: int}
     */
    private function quote(array $row, int $taxBps, int $discount, string $waiver): array
    {
        $price = RowValue::integer($row['price_minor'] ?? null);
        $exempt = self::flag($row['tax_exempt'] ?? null);

        return [
            'line_no' => RowValue::integer($row['line_no'] ?? null),
            'item_id' => RowValue::string($row['item_id'] ?? null),
            'code' => RowValue::string($row['code'] ?? null),
            'description' => RowValue::string($row['description'] ?? null),
            'vendor' => RowValue::string($row['vendor_name'] ?? null),
            'vendor_id' => RowValue::string($row['vendor_id'] ?? null),
            'price' => $price,
            'commission' => TicketQuote::halfUp($price, RowValue::integer($row['commission_bps'] ?? null)),
            'tax' => $exempt || $waiver !== '' ? 0 : TicketQuote::tax($price, $discount, $taxBps),
            'discount' => $discount,
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
}
