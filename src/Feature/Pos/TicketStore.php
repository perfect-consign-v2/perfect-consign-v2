<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Ledger\CashRound;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;


final class TicketStore
{
    public function __construct(
        private readonly Statements $statements,
        private readonly TicketPricing $pricing,
        private readonly TicketBuyer $buyers,
        private readonly TicketQueue $queue,
        private readonly TicketCustomer $customers,
    ) {
    }

    /**
     * @return array{id: string, name: string}
     */
    public function getShopper(string $ticketId): array
    {
        return $this->customers->current($ticketId);
    }

    /**
     * @return array{id: string, name: string}|null
     */
    public function openDrawer(): ?array
    {
        $rows = $this->statements->rows(
            "SELECT session.id, register.name
             FROM tickets AS ticket
             JOIN register_sessions AS session ON session.id = ticket.session_id
             JOIN registers AS register ON register.id = session.register_id
             WHERE ticket.status = 'open' AND session.closed_at IS NULL
             ORDER BY session.opened_at
             LIMIT 1"
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => RowValue::string($row['id'] ?? null),
            'name' => RowValue::string($row['name'] ?? null),
        ];
    }

    /**
     * @return array{
     *     ticket_id: string,
     *     lines: list<array{line_no: int, item_id: string, code: string, description: string, vendor: string, vendor_id: string, price: int, quantity: int, commission: int, tax: int, discount: int, line_discount: int, line_discount_kind: string, line_discount_value: string}>,
     *     merchandise: int,
     *     discount: int,
     *     discount_reason: string,
     *     tax: int,
     *     tax_bps: int,
     *     waiver: string,
     *     due: int,
     *     cash_round: int,
     *     cash_due: int,
     *     rounding: string,
     *     buyer_id: string,
     *     buyer_name: string,
     *     parked: list<array{id: string, pieces: int, sold: int, status: string}>
     * }
     */
    public function cart(string $sessionId): array
    {
        $mall = $this->mall();
        $ticketId = $this->openId($sessionId);
        $adjustment = $ticketId === null ? $this->pricing->emptyAdjustment() : $this->pricing->adjustment($ticketId);
        $lines = $ticketId === null ? [] : $this->pricing->lines($ticketId, $mall['tax'], $adjustment);
        $merchandise = 0;
        $discount = 0;
        $tax = 0;
        foreach ($lines as $line) {
            $merchandise += $line['price'] * $line['quantity'];
            $discount += $line['discount'];
            $tax += $line['tax'];
        }
        $due = $merchandise - $discount + $tax;
        $round = $mall['round'] === '1' ? CashRound::adjustment($due) : 0;
        $buyer = $this->buyers->current($ticketId);

        return [
            'ticket_id' => $ticketId ?? '',
            'lines' => $lines,
            'merchandise' => $merchandise,
            'discount' => $discount,
            'discount_reason' => $adjustment['reason'],
            'tax' => $tax,
            'tax_bps' => $mall['tax'],
            'waiver' => $adjustment['waiver'],
            'due' => $due,
            'cash_round' => $round,
            'cash_due' => $due + $round,
            'rounding' => $mall['round'],
            'buyer_id' => $buyer['id'],
            'buyer_name' => $buyer['name'],
            'parked' => $this->parked(),
        ];
    }

    public function scan(string $sessionId, string $code): void
    {
        if ($code === '') {
            throw new DatabaseRefusal('scan a barcode or a tag');
        }
        $piece = $this->piece($code);
        $this->statements->transaction(function () use ($sessionId, $piece): void {
            $ticketId = $this->openTicket($sessionId);
            $this->add($ticketId, $piece['id'], $piece['price']);
            $this->pricing->recheck($ticketId);
        });
    }

    public function price(string $sessionId, int $lineNo, int $price): void
    {
        $ticketId = $this->requireOpen($sessionId);
        $this->statements->transaction(function () use ($ticketId, $lineNo, $price): void {
            $updated = $this->statements->affected(
                'UPDATE ticket_lines SET price_minor = ? WHERE ticket_id = ?::uuid AND line_no = ?',
                [$price, $ticketId, $lineNo]
            );
            if ($updated !== 1) {
                throw new DatabaseRefusal('that piece is not on the ticket');
            }
            $this->pricing->recheck($ticketId);
        });
    }

    public function remove(string $sessionId, int $lineNo): void
    {
        $ticketId = $this->requireOpen($sessionId);
        $this->statements->transaction(function () use ($ticketId, $lineNo): void {
            $deleted = $this->statements->affected(
                'DELETE FROM ticket_lines WHERE ticket_id = ?::uuid AND line_no = ?',
                [$ticketId, $lineNo]
            );
            if ($deleted !== 1) {
                throw new DatabaseRefusal('that piece is not on the ticket');
            }
            $this->pricing->recheck($ticketId);
        });
    }

    public function discount(string $sessionId, ?string $kind, ?int $value, ?string $reason): void
    {
        $this->pricing->discount($this->requireOpen($sessionId), $kind, $value, $reason);
    }

    public function waive(string $sessionId, ?string $reason): void
    {
        $this->pricing->waive($this->requireOpen($sessionId), $reason);
    }

    public function park(string $sessionId): void
    {
        $this->queue->park($sessionId);
    }

    /**
     * @return list<array{id: string, customer: string, pieces: int, deposits: int, balance: int, last: string}>
     */
    public function layaways(): array
    {
        return $this->queue->layaways();
    }

    public function recall(string $sessionId, string $ticketId): void
    {
        $this->queue->recall($sessionId, $ticketId);
    }

    public function discard(string $sessionId): void
    {
        $deleted = $this->statements->affected(
            'DELETE FROM tickets WHERE session_id = ?::uuid AND status = ?',
            [$sessionId, 'open']
        );
        if ($deleted !== 1) {
            throw new DatabaseRefusal('there is no open ticket');
        }
    }

    /**
     * @return array{tax: int, round: string}
     */
    private function mall(): array
    {
        $rows = $this->statements->rows('SELECT tax_bps, cash_round_nickel FROM mall');
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            throw new DatabaseRefusal('the mall has no clock');
        }

        return [
            'tax' => RowValue::integer($row['tax_bps'] ?? null),
            'round' => self::flag($row['cash_round_nickel'] ?? null) ? '1' : '',
        ];
    }

    private function openId(string $sessionId): ?string
    {
        $rows = $this->statements->bound(
            'SELECT id FROM tickets WHERE session_id = ?::uuid AND status = ?',
            [$sessionId, 'open']
        );
        if ($rows === []) {
            return null;
        }

        return RowValue::string($rows[0]['id'] ?? null);
    }

    private function requireOpen(string $sessionId): string
    {
        $ticketId = $this->openId($sessionId);
        if ($ticketId === null) {
            throw new DatabaseRefusal('there is no open ticket');
        }

        return $ticketId;
    }

    private function openTicket(string $sessionId): string
    {
        $existing = $this->openId($sessionId);
        if ($existing !== null) {
            return $existing;
        }
        $id = $this->newId();
        $this->statements->change(
            'INSERT INTO tickets (id, session_id) VALUES (?::uuid, ?::uuid)',
            [$id, $sessionId]
        );

        return $id;
    }

    /**
     * @return array{id: string, price: int}
     */
    private function piece(string $code): array
    {
        $rows = $this->statements->bound(
            'SELECT id, price_minor FROM items
             WHERE (barcode IS NOT NULL AND lower(barcode) = lower(?))
                OR lower(code) = lower(?)',
            [$code, $code]
        );
        if (count($rows) > 1) {
            throw new DatabaseRefusal('that scan matches more than one piece');
        }
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            throw new DatabaseRefusal('that piece is not on the floor');
        }

        return [
            'id' => RowValue::string($row['id'] ?? null),
            'price' => RowValue::integer($row['price_minor'] ?? null),
        ];
    }

    private function add(string $ticketId, string $itemId, int $price): void
    {
        $rows = $this->statements->bound(
            'SELECT line_no FROM ticket_lines WHERE ticket_id = ?::uuid AND item_id = ?::uuid',
            [$ticketId, $itemId]
        );
        if ($rows !== []) {
            $updated = $this->statements->affected(
                'UPDATE ticket_lines SET quantity = quantity + 1 WHERE ticket_id = ?::uuid AND item_id = ?::uuid',
                [$ticketId, $itemId]
            );
            if ($updated !== 1) {
                throw new DatabaseRefusal('that piece is not on the ticket');
            }

            return;
        }
        $this->statements->change(
            'INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor, quantity) VALUES (?::uuid, ?, ?::uuid, ?, 1)',
            [$ticketId, $this->nextLine($ticketId), $itemId, $price]
        );
    }

    private function nextLine(string $ticketId): int
    {
        $rows = $this->statements->bound(
            'SELECT COALESCE(MAX(line_no), 0) + 1 AS line_no FROM ticket_lines WHERE ticket_id = ?::uuid',
            [$ticketId]
        );

        return RowValue::integer($rows[0]['line_no'] ?? null);
    }

    /**
     * @return list<array{id: string, pieces: int, sold: int, status: string}>
     */
    private function parked(): array
    {
        $rows = $this->statements->rows(
            'SELECT ticket.id, ticket.status, count(line.line_no) AS pieces,
                    COALESCE(sum(line.price_minor * line.quantity), 0) AS sold
             FROM tickets AS ticket
             LEFT JOIN ticket_lines AS line ON line.ticket_id = ticket.id
             WHERE ticket.status IN (\'parked\', \'layaway\')
             GROUP BY ticket.id, ticket.status
             ORDER BY ticket.id'
        );
        $parked = [];
        foreach ($rows as $row) {
            $parked[] = [
                'id' => RowValue::string($row['id'] ?? null),
                'pieces' => RowValue::integer($row['pieces'] ?? null),
                'sold' => RowValue::integer($row['sold'] ?? null),
                'status' => RowValue::string($row['status'] ?? null),
            ];
        }

        return $parked;
    }

    private function newId(): string
    {
        $rows = $this->statements->rows('SELECT uuidv7() AS id');

        return RowValue::string($rows[0]['id'] ?? null);
    }

    private static function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
