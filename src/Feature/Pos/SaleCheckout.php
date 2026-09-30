<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Ledger\CashRound;
use PerfectConsign\Ledger\DatabaseRefusal;
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

    /**
     * @return array{path: string, notice: string}
     */
    public function pay(string $slug, string $session, Request $request): array
    {
        $this->sales->refuse($slug);
        $cart = $this->tickets->cart($session);
        $deposits = $this->layaways->balance($cart['ticket_id']);
        $holding = $request->field('hold') === '1';
        $entered = $holding ? $this->tenders->read($request) : ($this->held->split($cart['ticket_id'], $cart['due'], $deposits) ?? $this->tenders->read($request));
        if ($holding && $entered['sum'] < $cart['due'] - $deposits) {
            if ($cart['buyer_id'] === '') {
                throw new DatabaseRefusal('Choose a customer to hold a layaway.');
            }
            $this->layaways->hold($session, $this->mall->today(), $cart['ticket_id'], $cart['due'], $entered);
            $name = $cart['buyer_name'] !== '' ? $cart['buyer_name'] : 'the customer';

            return [
                'path' => '/' . $slug . '/pos/layaway/' . $cart['ticket_id'],
                'notice' => 'Layaway held for ' . $name . '.',
            ];
        }
        $tender = $this->layaways->include($cart['ticket_id'], $deposits, $entered);
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

        return [
            'path' => '/' . $slug . '/pos',
            'notice' => 'Sale posted.',
        ];
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
