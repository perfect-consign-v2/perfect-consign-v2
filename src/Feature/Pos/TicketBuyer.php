<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class TicketBuyer
{
    public function __construct(private readonly Statements $statements)
    {
    }

    /**
     * @return array{id: string, name: string}
     */
    public function current(?string $ticketId): array
    {
        if ($ticketId === null || $ticketId === '') {
            return ['id' => '', 'name' => ''];
        }
        $rows = $this->statements->bound(
            'SELECT ticket.buyer_id, vendor.name
             FROM tickets AS ticket
             LEFT JOIN vendors AS vendor ON vendor.id = ticket.buyer_id
             WHERE ticket.id = ?::uuid',
            [$ticketId]
        );
        $row = $rows[0] ?? null;
        if (!is_array($row) || !is_string($row['buyer_id'] ?? null)) {
            return ['id' => '', 'name' => ''];
        }

        return [
            'id' => $row['buyer_id'],
            'name' => RowValue::string(is_string($row['name'] ?? null) ? $row['name'] : ''),
        ];
    }

    public function save(string $ticketId, string $vendorId): void
    {
        if ($ticketId === '' || preg_match('/^[0-9a-fA-F-]{36}$/', $vendorId) !== 1) {
            throw new DatabaseRefusal($ticketId === '' ? 'there is no open ticket' : 'Choose a vendor.');
        }
        $updated = $this->statements->affected(
            'UPDATE tickets SET buyer_id = ?::uuid
             WHERE id = ?::uuid AND status = ?
               AND EXISTS (SELECT 1 FROM vendors WHERE id = ?::uuid)',
            [$vendorId, $ticketId, 'open', $vendorId]
        );
        if ($updated !== 1) {
            throw new DatabaseRefusal('Choose a vendor.');
        }
    }

    public function clear(string $ticketId): void
    {
        $updated = $this->statements->affected(
            'UPDATE tickets SET buyer_id = NULL WHERE id = ?::uuid AND status = ?',
            [$ticketId, 'open']
        );
        if ($updated !== 1) {
            throw new DatabaseRefusal('there is no open ticket');
        }
    }
}
