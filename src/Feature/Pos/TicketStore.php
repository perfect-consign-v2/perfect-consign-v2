<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Ledger\CashRound;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class TicketStore
{
    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * @return array{
     *     ticket_id: string,
     *     lines: list<array{line_no: int, item_id: string, code: string, description: string, vendor: string, vendor_id: string, price: int, commission: int, tax: int}>,
     *     merchandise: int,
     *     tax: int,
     *     due: int,
     *     cash_round: int,
     *     cash_due: int,
     *     rounding: string,
     *     parked: list<array{id: string, pieces: int}>
     * }
     */
    public function cart(string $sessionId): array
    {
        $mall = $this->mall();
        $ticketId = $this->openId($sessionId);
        $lines = $ticketId === null ? [] : $this->lines($ticketId, $mall['tax']);
        $merchandise = 0;
        $tax = 0;
        foreach ($lines as $line) {
            $merchandise += $line['price'];
            $tax += $line['tax'];
        }
        $due = $merchandise + $tax;
        $round = $mall['round'] === '1' ? CashRound::adjustment($due) : 0;

        return [
            'ticket_id' => $ticketId ?? '',
            'lines' => $lines,
            'merchandise' => $merchandise,
            'tax' => $tax,
            'due' => $due,
            'cash_round' => $round,
            'cash_due' => $due + $round,
            'rounding' => $mall['round'],
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
            $this->guardNewPiece($ticketId, $piece['id']);
            $this->statements->change(
                'INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor) VALUES (?::uuid, ?, ?::uuid, ?)',
                [$ticketId, $this->nextLine($ticketId), $piece['id'], $piece['price']]
            );
        });
    }

    public function price(string $sessionId, int $lineNo, int $price): void
    {
        $updated = $this->statements->affected(
            'UPDATE ticket_lines SET price_minor = ? WHERE ticket_id = ?::uuid AND line_no = ?',
            [$price, $this->requireOpen($sessionId), $lineNo]
        );
        if ($updated !== 1) {
            throw new DatabaseRefusal('that piece is not on the ticket');
        }
    }

    public function remove(string $sessionId, int $lineNo): void
    {
        $deleted = $this->statements->affected(
            'DELETE FROM ticket_lines WHERE ticket_id = ?::uuid AND line_no = ?',
            [$this->requireOpen($sessionId), $lineNo]
        );
        if ($deleted !== 1) {
            throw new DatabaseRefusal('that piece is not on the ticket');
        }
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

    public function recall(string $sessionId, string $ticketId): void
    {
        if ($this->openId($sessionId) !== null) {
            throw new DatabaseRefusal('this shift already has an open ticket');
        }
        $updated = $this->statements->affected(
            'UPDATE tickets SET status = ?, session_id = ?::uuid WHERE id = ?::uuid AND status = ?',
            ['open', $sessionId, $ticketId, 'parked']
        );
        if ($updated !== 1) {
            throw new DatabaseRefusal('that ticket is not parked');
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

    private function guardNewPiece(string $ticketId, string $itemId): void
    {
        $rows = $this->statements->bound(
            'SELECT 1 FROM ticket_lines WHERE ticket_id = ?::uuid AND item_id = ?::uuid',
            [$ticketId, $itemId]
        );
        if ($rows !== []) {
            throw new DatabaseRefusal('a piece is already on the ticket');
        }
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
     * @return list<array{line_no: int, item_id: string, code: string, description: string, vendor: string, vendor_id: string, price: int, commission: int, tax: int}>
     */
    private function lines(string $ticketId, int $taxBps): array
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
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = $this->quote($row, $taxBps);
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{line_no: int, item_id: string, code: string, description: string, vendor: string, vendor_id: string, price: int, commission: int, tax: int}
     */
    private function quote(array $row, int $taxBps): array
    {
        $price = RowValue::integer($row['price_minor'] ?? null);

        return [
            'line_no' => RowValue::integer($row['line_no'] ?? null),
            'item_id' => RowValue::string($row['item_id'] ?? null),
            'code' => RowValue::string($row['code'] ?? null),
            'description' => RowValue::string($row['description'] ?? null),
            'vendor' => RowValue::string($row['vendor_name'] ?? null),
            'vendor_id' => RowValue::string($row['vendor_id'] ?? null),
            'price' => $price,
            'commission' => TicketQuote::halfUp($price, RowValue::integer($row['commission_bps'] ?? null)),
            'tax' => self::flag($row['tax_exempt'] ?? null) ? 0 : TicketQuote::halfUp($price, $taxBps),
        ];
    }

    /**
     * @return list<array{id: string, pieces: int}>
     */
    private function parked(): array
    {
        $rows = $this->statements->rows(
            'SELECT ticket.id, count(line.line_no) AS pieces
             FROM tickets AS ticket
             LEFT JOIN ticket_lines AS line ON line.ticket_id = ticket.id
             WHERE ticket.status = \'parked\'
             GROUP BY ticket.id
             ORDER BY ticket.id'
        );
        $parked = [];
        foreach ($rows as $row) {
            $parked[] = [
                'id' => RowValue::string($row['id'] ?? null),
                'pieces' => RowValue::integer($row['pieces'] ?? null),
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
