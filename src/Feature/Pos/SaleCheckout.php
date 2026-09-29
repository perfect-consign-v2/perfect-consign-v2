<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\Money;
use PerfectConsign\Shared\Http\Request;

final class SaleCheckout
{
    public function __construct(
        private readonly TicketStore $tickets,
        private readonly TicketTenders $held,
        private readonly TenderSplit $tenders,
        private readonly SalePoster $poster,
        private readonly LayawayBook $layaways,
        private readonly MallSettings $mall,
        private readonly SaleHold $sales,
    ) {
    }

    public function pay(string $slug, string $session, Request $request): string
    {
        $this->sales->refuse($slug);
        $cart = $this->tickets->cart($session);
        $round = $this->mall->cashRoundNickel() ? 1 : 0;
        $deposits = $this->layaways->balance($cart['ticket_id']);
        $journalId = $this->poster->pay(
            $session,
            $this->mall->today(),
            $round,
            $cart,
            $this->layaways->include(
                $cart['ticket_id'],
                $deposits,
                $this->held->split($cart['ticket_id'], $cart['due'], $round, $deposits)
                    ?? $this->tenders->read($request),
            ),
            $this->handed($request),
        );
        $this->sales->keep($slug, $journalId, $request->field('cash_received'));

        return $journalId;
    }

    private function handed(Request $request): ?int
    {
        $value = $request->field('cash_received');
        if ($value === '') {
            return null;
        }

        return Money::parseMinor($value);
    }
}
