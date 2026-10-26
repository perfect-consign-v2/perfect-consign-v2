<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

final class PosRegister
{
    public function __construct(
        private readonly TicketStore $tickets,
        private readonly TicketTenders $held,
        private readonly TicketDrafts $drafts,
        private readonly RegisterGate $gate,
        private readonly LayawayBook $layaways,
    ) {
    }

    public function session(string $slug): string
    {
        return $this->gate->session($slug);
    }

    /**
     * @return array{
     *     ticket_id: string,
     *     posting_key: string,
     *     lines: list<array<string, mixed>>,
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
     *     parked: list<array{id: string, items: int, sold: int, status: string, label: string}>
     * }
     */
    public function cart(string $session): array
    {
        return $this->tickets->cart($session);
    }

    /**
     * @return list<array{
     *     id: string,
     *     draft_no: int,
     *     vendor_id: string,
     *     vendor_label: string,
     *     description: string,
     *     price_text: string,
     *     brand_id: string,
     *     brand_label: string,
     *     discount_kind: string,
     *     discount_value: string,
     *     attributes: array<string, array{id: string, label: string}>,
     *     missing: list<string>
     * }>
     */
    public function drafts(string $session): array
    {
        return $this->drafts->forSession($session);
    }

    /**
     * @return list<array{role: string, amount: int, reference: string}>
     */
    public function tenders(string $ticketId): array
    {
        return $this->held->list($ticketId);
    }

    public function deposits(string $ticketId): int
    {
        return $this->layaways->balance($ticketId);
    }

    /**
     * @return list<array{posted_on: string, tender: string, amount: int, kind: string}>
     */
    public function payments(string $ticketId): array
    {
        return $this->layaways->payments($ticketId);
    }

    /**
     * @return array{id: string, name: string}
     */
    public function shopper(string $ticketId): array
    {
        return $this->tickets->getShopper($ticketId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function layaways(): array
    {
        return $this->tickets->layaways();
    }
}
