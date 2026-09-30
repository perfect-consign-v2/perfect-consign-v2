<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class LayawaySlip
{
    public function __construct(
        private readonly Statements $statements,
        private readonly TicketPricing $pricing,
        private readonly LayawayBook $layaways,
    ) {
    }

    /**
     * @return array{
     *     ticket_id: string,
     *     customer: string,
     *     cancelled: bool,
     *     lines: list<array{description: string, code: string, quantity: int, amount: int}>,
     *     due: int,
     *     deposits: int,
     *     balance: int,
     *     payments: list<array{posted_on: string, tender: string, amount: int, kind: string}>
     * }
     */
    public function read(string $ticketId): array
    {
        $slip = $this->ticket($ticketId);
        $slip['cancelled'] = false;
        $slip['payments'] = $this->layaways->payments($ticketId);
        $slip['deposits'] = $this->layaways->balance($ticketId);
        $slip['balance'] = max(0, $slip['due'] - $slip['deposits']);

        return $slip;
    }

    /**
     * @param array{
     *     ticket_id: string,
     *     customer: string,
     *     cancelled: bool,
     *     lines: list<array{description: string, code: string, quantity: int, amount: int}>,
     *     due: int,
     *     deposits: int,
     *     balance: int,
     *     payments: list<array{posted_on: string, tender: string, amount: int, kind: string}>
     * } $slip
     * @return array{
     *     ticket_id: string,
     *     customer: string,
     *     cancelled: bool,
     *     lines: list<array{description: string, code: string, quantity: int, amount: int}>,
     *     due: int,
     *     deposits: int,
     *     balance: int,
     *     payments: list<array{posted_on: string, tender: string, amount: int, kind: string}>
     * }
     */
    public function cancelled(array $slip): array
    {
        $slip['cancelled'] = true;
        $slip['payments'] = $this->layaways->payments($slip['ticket_id']);
        $slip['deposits'] = 0;
        $slip['balance'] = 0;

        return $slip;
    }

    /**
     * @return array{
     *     ticket_id: string,
     *     customer: string,
     *     cancelled: bool,
     *     lines: list<array{description: string, code: string, quantity: int, amount: int}>,
     *     due: int,
     *     deposits: int,
     *     balance: int,
     *     payments: list<array{posted_on: string, tender: string, amount: int, kind: string}>
     * }
     */
    private function ticket(string $ticketId): array
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $ticketId) !== 1) {
            throw new DatabaseRefusal('that ticket is not parked');
        }
        $rows = $this->statements->bound(
            "SELECT COALESCE(vendor.name, '') AS customer
             FROM tickets AS ticket
             LEFT JOIN vendors AS vendor ON vendor.id = ticket.buyer_id
             WHERE ticket.id = ?::uuid
               AND ticket.status IN ('open', 'layaway', 'posted')",
            [$ticketId]
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            throw new DatabaseRefusal('that ticket is not parked');
        }
        $mall = $this->statements->rows('SELECT tax_bps FROM mall');
        $tax = RowValue::integer($mall[0]['tax_bps'] ?? null);
        $adjustment = $this->pricing->adjustment($ticketId);
        $lines = [];
        $due = 0;
        foreach ($this->pricing->lines($ticketId, $tax, $adjustment) as $line) {
            $amount = ($line['price'] * $line['quantity']) - $line['discount'] + $line['tax'];
            $due += $amount;
            $lines[] = [
                'description' => $line['description'],
                'code' => $line['code'],
                'quantity' => $line['quantity'],
                'amount' => $amount,
            ];
        }

        return [
            'ticket_id' => $ticketId,
            'customer' => RowValue::string(is_string($row['customer'] ?? null) ? $row['customer'] : ''),
            'cancelled' => false,
            'lines' => $lines,
            'due' => $due,
            'deposits' => 0,
            'balance' => $due,
            'payments' => [],
        ];
    }
}
