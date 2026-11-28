<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Bootstrap\JournalAudit;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;

final class TicketPriceChange
{
    public function __construct(
        private readonly Statements $statements,
        private readonly TicketPricing $pricing,
        private readonly JournalAudit $audit,
    ) {
    }

    /**
     * Sets the line's price. The database refuses a price below the item's
     * floor ("that price is below the item floor").
     */
    public function apply(string $ticketId, int $lineNo, int $price): void
    {
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
}
