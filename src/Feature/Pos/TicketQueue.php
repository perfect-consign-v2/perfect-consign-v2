<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class TicketQueue
{
    public function __construct(
        private readonly Statements $statements,
        private readonly TicketPricing $pricing,
    ) {
    }

    public function park(string $sessionId): void
    {
        $updated = $this->statements->affected(
            'UPDATE tickets SET status = ? WHERE session_id = ?::uuid AND status = ?',
            ['parked', $sessionId, 'open']
        );
        if ($updated !== 1) {
            throw new DatabaseRefusal('there is no open ticket');
        }
    }

    /**
     * @return list<array{id: string, customer: string, pieces: int, deposits: int, balance: int, last: string}>
     */
    public function layaways(): array
    {
        $tax = $this->taxRate();
        $rows = $this->statements->rows(
            "SELECT ticket.id, COALESCE(vendor.name, '') AS customer,
                    count(line.line_no) AS pieces,
                    COALESCE((
                        SELECT sum(journal_line.credit_minor - journal_line.debit_minor)
                        FROM journal_lines AS journal_line
                        JOIN chart_roles AS role ON role.account_id = journal_line.account_id
                        WHERE role.role = 'layaway_deposit'
                          AND journal_line.subledger_type = 'ticket'
                          AND journal_line.subledger_ref = ticket.id::text
                    ), 0) AS deposits,
                    (
                        SELECT to_char(max(journal.posted_at), 'YYYY-MM-DD')
                        FROM journals AS journal
                        WHERE journal.source_type = 'layaway_deposit'
                          AND journal.source_reference = ticket.id::text
                    ) AS last_deposit
             FROM tickets AS ticket
             LEFT JOIN vendors AS vendor ON vendor.id = ticket.buyer_id
             LEFT JOIN ticket_lines AS line ON line.ticket_id = ticket.id
             WHERE ticket.status = 'layaway'
             GROUP BY ticket.id, vendor.name
             ORDER BY ticket.id"
        );
        $held = [];
        foreach ($rows as $row) {
            $id = RowValue::string($row['id'] ?? null);
            $adjustment = $this->pricing->adjustment($id);
            $due = 0;
            foreach ($this->pricing->lines($id, $tax, $adjustment) as $line) {
                $due += ($line['price'] * $line['quantity']) - $line['discount'] + $line['tax'];
            }
            $deposits = RowValue::integer($row['deposits'] ?? null);
            $last = $row['last_deposit'] ?? null;
            $held[] = [
                'id' => $id,
                'customer' => RowValue::string(is_string($row['customer'] ?? null) ? $row['customer'] : ''),
                'pieces' => RowValue::integer($row['pieces'] ?? null),
                'deposits' => $deposits,
                'balance' => max(0, $due - $deposits),
                'last' => is_string($last) ? $last : '',
            ];
        }

        return $held;
    }

    public function recall(string $sessionId, string $ticketId): void
    {
        $open = $this->statements->bound(
            'SELECT id FROM tickets WHERE session_id = ?::uuid AND status = ?',
            [$sessionId, 'open']
        );
        if ($open !== []) {
            throw new DatabaseRefusal('this shift already has an open ticket');
        }
        $updated = $this->statements->affected(
            "UPDATE tickets SET status = 'open', session_id = ?::uuid WHERE id = ?::uuid AND status IN ('parked', 'layaway')",
            [$sessionId, $ticketId]
        );
        if ($updated !== 1) {
            throw new DatabaseRefusal('that ticket is not parked');
        }
    }

    private function taxRate(): int
    {
        $rows = $this->statements->rows('SELECT tax_bps FROM mall');
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            throw new DatabaseRefusal('the mall has no clock');
        }

        return RowValue::integer($row['tax_bps'] ?? null);
    }
}
