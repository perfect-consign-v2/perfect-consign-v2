<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class TicketCustomer
{
    public function __construct(
        private readonly Statements $statements,
        private readonly CustomerStore $customers,
    ) {
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
            'SELECT ticket.customer_party_id,
                    stall_party_display_name(ticket.customer_party_id) AS name
             FROM tickets AS ticket
             WHERE ticket.id = ?::uuid',
            [$ticketId]
        );
        $row = $rows[0] ?? null;
        if (!is_array($row) || !is_string($row['customer_party_id'] ?? null)) {
            return ['id' => '', 'name' => ''];
        }

        return [
            'id' => $row['customer_party_id'],
            'name' => RowValue::string(is_string($row['name'] ?? null) ? $row['name'] : ''),
        ];
    }

    public function save(string $ticketId, string $name, string $phone, string $email): void
    {
        if ($ticketId === '') {
            throw new DatabaseRefusal('there is no open ticket');
        }
        if (trim($name) === '') {
            $this->clear($ticketId);

            return;
        }
        $partyId = $this->customers->findOrCreate($name, $phone, $email);
        $updated = $this->statements->affected(
            'UPDATE tickets SET customer_party_id = ?::uuid WHERE id = ?::uuid AND status = ?',
            [$partyId, $ticketId, 'open']
        );
        if ($updated !== 1) {
            throw new DatabaseRefusal('there is no open ticket');
        }
    }

    public function clear(string $ticketId): void
    {
        $updated = $this->statements->affected(
            'UPDATE tickets SET customer_party_id = NULL WHERE id = ?::uuid AND status = ?',
            [$ticketId, 'open']
        );
        if ($updated !== 1) {
            throw new DatabaseRefusal('there is no open ticket');
        }
    }
}
