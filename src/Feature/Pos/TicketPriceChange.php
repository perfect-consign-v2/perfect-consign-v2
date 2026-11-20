<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Bootstrap\JournalAudit;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class TicketPriceChange
{
    public function __construct(
        private readonly Statements $statements,
        private readonly TicketPricing $pricing,
        private readonly JournalAudit $audit,
    ) {
    }

    public function apply(string $ticketId, int $lineNo, int $price): void
    {
        $this->itemIdBelowFloor($ticketId, $lineNo, $price);
        $this->statements->transaction(function () use ($ticketId, $lineNo, $price): void {
            $updated = $this->statements->affected(
                'UPDATE ticket_lines SET price_minor = ? WHERE ticket_id = ?::uuid AND line_no = ?',
                [$price, $ticketId, $lineNo]
            );
            if ($updated !== 1) {
                throw new DatabaseRefusal('that item is not on the ticket');
            }
            $this->audit->record('sale.price', 'ticket', $ticketId);
            $this->pricing->recheck($ticketId);
        });
    }

    private function itemIdBelowFloor(string $ticketId, int $lineNo, int $price): string
    {
        $rows = $this->statements->bound(
            'SELECT line.item_id, floor.floor_minor
             FROM ticket_lines AS line
             LEFT JOIN item_floors AS floor ON floor.item_id = line.item_id
             WHERE line.ticket_id = ?::uuid AND line.line_no = ?',
            [$ticketId, $lineNo]
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            throw new DatabaseRefusal('that item is not on the ticket');
        }
        $floor = $row['floor_minor'] ?? null;
        if ($floor !== null && $price < RowValue::integer($floor)) {
            throw new DatabaseRefusal('that price is below the item floor');
        }

        return RowValue::string($row['item_id'] ?? null);
    }
}
