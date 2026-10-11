<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;

final class TicketLines
{
    public function __construct(
        private readonly Statements $statements,
        private readonly TicketPricing $pricing,
    ) {
    }

    public function quantity(string $ticketId, int $lineNo, int $quantity): void
    {
        if ($ticketId === '' || $quantity < 1) {
            throw new DatabaseRefusal($ticketId === '' ? 'there is no open ticket' : 'there are not enough on hand');
        }
        $this->statements->transaction(function () use ($ticketId, $lineNo, $quantity): void {
            $updated = $this->statements->affected(
                'UPDATE ticket_lines SET quantity = ? WHERE ticket_id = ?::uuid AND line_no = ?',
                [$quantity, $ticketId, $lineNo]
            );
            if ($updated !== 1) {
                throw new DatabaseRefusal('that item is not on the ticket');
            }
            $this->pricing->recheck($ticketId);
        });
    }

    public function discount(string $ticketId, int $lineNo, ?string $kind, ?int $value, ?string $reason): void
    {
        if ($ticketId === '') {
            throw new DatabaseRefusal('there is no open ticket');
        }
        $this->statements->transaction(function () use ($ticketId, $lineNo, $kind, $value, $reason): void {
            $updated = $this->statements->affected(
                'UPDATE ticket_lines
                 SET line_discount_kind = ?, line_discount_value = ?, line_discount_reason = ?
                 WHERE ticket_id = ?::uuid AND line_no = ?',
                [$kind, $value, $reason, $ticketId, $lineNo]
            );
            if ($updated !== 1) {
                throw new DatabaseRefusal('that item is not on the ticket');
            }
            $this->pricing->recheck($ticketId);
        });
    }
}
