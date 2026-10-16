<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Rent;

use PerfectConsign\Feature\Mall\MallPayoutSettings;
use PerfectConsign\Feature\Payout\PayoutSettler;
use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\JournalSource;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\Money;
use PerfectConsign\Ledger\PostedLines;

final class RentPosting
{
    public function __construct(
        private readonly LedgerStore $ledger,
        private readonly PayoutStore $payouts,
        private readonly PayoutSettler $settler,
        private readonly MallPayoutSettings $settings,
        private readonly RentMoves $moves,
    ) {
    }

    /**
     * @param array{cash: string, card: string, checks: string, tendered: string, check: string} $tender
     * @return list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}>
     */
    public function tenders(int $amount, array $tender): array
    {
        $cash = $this->minor($tender['cash']);
        $card = $this->minor($tender['card']);
        $checks = $this->minor($tender['checks']);
        if ($cash + $card + $checks !== $amount) {
            throw new DatabaseRefusal('a rent receipt needs a tender');
        }
        $this->requireCheck($checks, trim($tender['check']));
        $this->handed($cash, $tender['tendered']);

        return $this->debitLines($cash, $card, $checks);
    }

    /**
     * @param array{cash: string, card: string, checks: string, tendered: string, check: string} $tender
     * @param list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}> $debits
     */
    public function postReceipt(
        string $vendorId,
        string $today,
        string $sessionId,
        int $amount,
        array $tender,
        array $debits,
    ): string {
        if ($this->settings->rentPrepaid()) {
            $quote = $this->payouts->quotedRun($vendorId, $today);
            if ($quote === null) {
                throw new DatabaseRefusal('a rent receipt cannot exceed the open rent');
            }
            $this->settler->chargeMissing($quote['run_id'], $vendorId);
        }
        $id = $this->ledger->newId();
        $check = $this->minor($tender['checks']) > 0 ? trim($tender['check']) : null;
        $lines = $debits;
        foreach ($this->credits($vendorId, $amount) as $credit) {
            $lines[] = $credit;
        }

        return $this->ledger->post(new JournalDraft(
            postingKey: 'rent-payment:' . $id,
            postingDate: $today,
            source: new JournalSource('rent_payment', $id, 'Rent receipt'),
            lines: $lines,
            sessionId: $sessionId,
            checkNumber: $check,
            tenderedMinor: $this->tenderedMinor($tender),
        ));
    }

    /**
     * @return list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}>
     */
    private function credits(string $vendorId, int $amount): array
    {
        $placed = 0;
        $lines = [];
        foreach ((new RentBill())->share($this->moves->charges($vendorId), $amount) as $share) {
            $placed += $share['amount'];
            $lines[] = PostedLines::entry(
                $this->ledger->roleAccount('booth_rent_receivable'),
                0,
                $share['amount'],
                'booth',
                $share['booth_id'],
            );
        }
        if ($placed !== $amount || $lines === []) {
            throw new DatabaseRefusal('a rent receipt cannot exceed the open rent');
        }

        return $lines;
    }

    private function requireCheck(int $checks, string $number): void
    {
        if ($checks > 0 && ($number === '' || strlen($number) > 40)) {
            throw new DatabaseRefusal('a rent check needs a check number');
        }
        if ($checks < 1 && $number !== '') {
            throw new DatabaseRefusal('a cash or card rent receipt has no check number');
        }
    }

    /**
     * @param array{cash: string, card: string, checks: string, tendered: string, check: string} $tender
     */
    private function tenderedMinor(array $tender): ?int
    {
        if ($this->minor($tender['cash']) < 1) {
            return null;
        }
        $raw = trim($tender['tendered']);

        return $raw === '' ? $this->minor($tender['cash']) : $this->minor($raw);
    }

    private function handed(int $cash, string $raw): void
    {
        if ($cash < 1) {
            return;
        }
        $given = trim($raw) === '' ? $cash : $this->minor($raw);
        if ($given < $cash) {
            throw new DatabaseRefusal('the cash tendered does not cover the cash amount');
        }
    }

    /**
     * @return list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}>
     */
    private function debitLines(int $cash, int $card, int $checks): array
    {
        $lines = [];
        foreach (['cash' => $cash, 'card' => $card, 'checks' => $checks] as $role => $amount) {
            if ($amount < 1) {
                continue;
            }
            $lines[] = PostedLines::entry($this->ledger->roleAccount($role), $amount, 0, null, null);
        }
        if ($lines === []) {
            throw new DatabaseRefusal('a rent receipt needs a tender');
        }

        return $lines;
    }

    private function minor(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '0' || $raw === '0.0' || $raw === '0.00') {
            return 0;
        }

        return Money::parseMinor($raw);
    }
}
