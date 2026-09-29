<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\CashRound;
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
        $deposits = $this->layaways->balance($cart['ticket_id']);
        $tender = $this->layaways->include(
            $cart['ticket_id'],
            $deposits,
            $this->held->split($cart['ticket_id'], $cart['due'], $deposits)
                ?? $this->tenders->read($request),
        );
        $nonCash = $tender['sum'] - $tender['cash'];
        $residual = $cart['due'] - $nonCash;
        $allCash = $tender['cash'] > 0 && $nonCash === 0 && $deposits === 0;
        $roundOn = $this->mall->cashRoundNickel() && $allCash ? 1 : 0;
        $cashDue = $roundOn === 1 ? CashRound::rounded(max(0, $residual)) : max(0, $residual);
        $shown = $request->field('cash_received');
        if ($tender['cash'] > $cashDue) {
            if ($shown === '') {
                $shown = $this->dollars($tender['cash']);
            }
            $tender = $this->cashAt($tender, $cashDue);
        }
        $journalId = $this->poster->pay(
            $session,
            $this->mall->today(),
            $roundOn,
            $cart,
            $tender,
            $shown === '' ? null : Money::parseMinor($shown),
        );
        $this->sales->keep($slug, $journalId, $shown);

        return $journalId;
    }

    /**
     * @param array{
     *     lines: list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}>,
     *     sum: int,
     *     check: ?string,
     *     cash: int
     * } $tender
     * @return array{
     *     lines: list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}>,
     *     sum: int,
     *     check: ?string,
     *     cash: int
     * }
     */
    private function cashAt(array $tender, int $cash): array
    {
        $replaced = false;
        foreach ($tender['lines'] as $index => $line) {
            if ($replaced || $line['debit'] !== $tender['cash']) {
                continue;
            }
            $tender['lines'][$index]['debit'] = $cash;
            $replaced = true;
        }
        $tender['sum'] = $tender['sum'] - $tender['cash'] + $cash;
        $tender['cash'] = $cash;

        return $tender;
    }

    private function dollars(int $minor): string
    {
        return intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
