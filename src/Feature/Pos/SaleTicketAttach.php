<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class SaleTicketAttach
{
    public function __construct(private readonly Statements $statements)
    {
    }

    public function saleKey(string $ticketId, string $offered): string
    {
        $rows = $this->statements->bound(
            'SELECT posting_key FROM tickets WHERE id = ?::uuid',
            [$ticketId]
        );
        $stored = RowValue::string($rows[0]['posting_key'] ?? null);
        if ($offered !== '' && $offered !== $stored) {
            throw new DatabaseRefusal('the ticket key does not match');
        }

        return $stored;
    }

    public function posted(string $postingKey): ?string
    {
        $rows = $this->statements->bound(
            'SELECT id FROM journals WHERE posting_key = ?',
            [$postingKey]
        );
        if ($rows === []) {
            return null;
        }

        return RowValue::string($rows[0]['id'] ?? null);
    }

    public function clearTenders(string $ticketId): void
    {
        $this->statements->change('DELETE FROM ticket_tenders WHERE ticket_id = ?::uuid', [$ticketId]);
    }

    /**
     * @param list<array{line_no: int, item_id: string, price: int, quantity: int, commission: int, tax: int, discount: int, store_discount: int}> $items
     */
    public function attach(string $journalId, string $ticketId, array $items): void
    {
        $locked = $this->statements->bound(
            'SELECT id FROM tickets WHERE id = ?::uuid AND status = ? FOR UPDATE',
            [$ticketId, 'open']
        );
        if ($locked === []) {
            throw new DatabaseRefusal('a posted ticket cannot change');
        }
        $this->samePrices($ticketId, $items);
        foreach ($items as $item) {
            $this->statements->change(
                'INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, quantity, commission_minor, tax_minor, discount_minor, store_discount_minor)
                 VALUES (?::uuid, ?, ?::uuid, ?, ?, ?, ?, ?, ?)',
                [$journalId, $item['line_no'], $item['item_id'], $item['price'], $item['quantity'], $item['commission'], $item['tax'], $item['discount'], $item['store_discount']]
            );
        }
        $updated = $this->statements->affected(
            'UPDATE tickets SET status = ?, posted_journal_id = ?::uuid WHERE id = ?::uuid AND status = ?',
            ['posted', $journalId, $ticketId, 'open']
        );
        if ($updated !== 1) {
            throw new DatabaseRefusal('a posted ticket cannot change');
        }
    }

    /**
     * @param list<array{item_id: string, price: int, quantity: int}> $items
     */
    private function samePrices(string $ticketId, array $items): void
    {
        $rows = $this->statements->bound(
            'SELECT item_id, price_minor, quantity FROM ticket_lines WHERE ticket_id = ?::uuid ORDER BY line_no',
            [$ticketId]
        );
        if (count($rows) !== count($items)) {
            throw new DatabaseRefusal('the ticket changed before pay');
        }
        foreach ($items as $index => $item) {
            $this->sameItem($rows[$index] ?? null, $item);
        }
    }

    /**
     * @param array{item_id: string, price: int, quantity: int} $item
     */
    private function sameItem(mixed $row, array $item): void
    {
        if (!is_array($row)
            || RowValue::string($row['item_id'] ?? null) !== $item['item_id']
            || RowValue::integer($row['price_minor'] ?? null) !== $item['price']
            || RowValue::integer($row['quantity'] ?? null) !== $item['quantity']) {
            throw new DatabaseRefusal('the ticket changed before pay');
        }
    }
}
