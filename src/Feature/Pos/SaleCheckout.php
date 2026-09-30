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
        $entered = $this->entered($request, $holding, $cart['ticket_id'], $cart['due'], $deposits);
        if ($holding && $entered['sum'] < $cart['due'] - $deposits) {
            return $this->hold($slug, $session, $cart, $entered);
        }

        return $this->settle($slug, $session, $request, $cart, $deposits, $entered);
    }

    /**
     * @return array{
     *     lines: list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}>,
     *     sum: int,
     *     check: ?string,
     *     cash: int
     * }
     */
    private function entered(Request $request, bool $holding, string $ticketId, int $due, int $deposits): array
    {
        if ($holding) {
            return $this->tenders->read($request);
        }
        $held = $this->held->split($ticketId, $due, $deposits);
        if ($held !== null) {
            return $held;
        }

        return $this->tenders->read($request);
    }

    /**
     * @param array{ticket_id: string, due: int, buyer_id: string, buyer_name: string} $cart
     * @param array{
     *     lines: list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}>,
     *     sum: int,
     *     check: ?string,
     *     cash: int
     * } $entered
     * @return array{path: string, notice: string}
     */
    private function hold(string $slug, string $session, array $cart, array $entered): array
    {
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

    /**
     * @param array{
     *     ticket_id: string,
     *     lines: list<array{line_no: int, item_id: string, code: string, description: string, vendor: string, vendor_id: string, price: int, quantity: int, commission: int, tax: int, discount: int}>,
     *     due: int
     * } $cart
     * @param array{
     *     lines: list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}>,
     *     sum: int,
     *     check: ?string,
     *     cash: int
     * } $entered
     * @return array{path: string, notice: string}
     */
    private function settle(string $slug, string $session, Request $request, array $cart, int $deposits, array $entered): array
    {
        $tender = $this->layaways->include($cart['ticket_id'], $deposits, $entered);
        $nonCash = $tender['sum'] - $tender['cash'];
        $cashDue = $this->cashDue($tender['cash'], $nonCash, $deposits, $cart['due']);
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
            $this->roundOn($tender['cash'], $nonCash, $deposits),
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

    private function roundOn(int $cash, int $nonCash, int $deposits): int
    {
        $allCash = $cash > 0 && $nonCash === 0 && $deposits === 0;
        if ($this->mall->cashRoundNickel() && $allCash) {
            return 1;
        }

        return 0;
    }

    private function cashDue(int $cash, int $nonCash, int $deposits, int $due): int
    {
        $residual = max(0, $due - $nonCash);
        if ($this->roundOn($cash, $nonCash, $deposits) === 1) {
            return CashRound::rounded($residual);
        }

        return $residual;
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
