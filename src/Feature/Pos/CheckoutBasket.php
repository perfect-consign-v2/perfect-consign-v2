<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Shared\Http\Request;

final class CheckoutBasket
{
    public function __construct(
        private readonly TicketStore $tickets,
        private readonly TicketTenders $held,
        private readonly LayawayBook $layaways,
        private readonly TicketDrafts $drafts,
    ) {
    }

    public function hasDrafts(string $session): bool
    {
        return $this->drafts->hasAny($session);
    }

    /**
     * @return array{
     *     ticket_id: string,
     *     posting_key: string,
     *     lines: list<array{line_no: int, item_id: string, code: string, description: string, vendor: string, vendor_id: string, price: int, quantity: int, commission: int, tax: int, discount: int, store_discount: int}>,
     *     due: int,
     *     buyer_id: string,
     *     buyer_name: string
     * }
     */
    public function cart(string $session): array
    {
        return $this->tickets->cart($session);
    }

    public function deposits(string $ticketId): int
    {
        return $this->layaways->balance($ticketId);
    }

    /**
     * @return array{
     *     lines: list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}>,
     *     sum: int,
     *     check: ?string,
     *     cash: int
     * }|null
     */
    public function heldSplit(string $ticketId, int $due, int $deposits): ?array
    {
        return $this->held->split($ticketId, $due, $deposits);
    }

    /**
     * @param array{
     *     lines: list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}>,
     *     sum: int,
     *     check: ?string,
     *     cash: int
     * } $entered
     */
    public function holdLayaway(string $session, string $today, string $ticketId, int $due, array $entered): void
    {
        $this->layaways->hold($session, $today, $ticketId, $due, $entered);
    }

    /**
     * @param array{
     *     lines: list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}>,
     *     sum: int,
     *     check: ?string,
     *     cash: int
     * } $entered
     * @return array{
     *     lines: list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}>,
     *     sum: int,
     *     check: ?string,
     *     cash: int
     * }
     */
    public function includeDeposits(string $ticketId, int $deposits, array $entered): array
    {
        return $this->layaways->include($ticketId, $deposits, $entered);
    }
}
