<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Rent;

use PDO;
use PerfectConsign\Feature\Mall\MallPayoutSettings;
use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;

final class RentReceipt
{
    private bool $ownsTransaction = false;

    private RentOwed $owed;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Statements $statements,
        private readonly PayoutStore $payouts,
        private readonly MallPayoutSettings $settings,
        private readonly RentPosting $posting,
        private readonly RentSlip $slips,
        private readonly RentPayment $payments,
        private readonly LateFee $fees,
    ) {
        $this->owed = new RentOwed($statements, $payouts, $settings, $payments);
    }

    /**
     * Everything this vendor still owes, on every booth. Pack owes when sales pay the rent.
     *
     * @return array{vendor_id: string, vendor_name: string, booth_id: string, booth: string, owed: int, rent: int, late: int, collect: string}|null
     */
    public function figure(string $vendorId, string $boothId, string $today): ?array
    {
        return $this->owed->figure($vendorId, $boothId, $today);
    }

    /**
     * @param array{cash: string, card: string, checks: string, tendered: string, check: string, way?: string} $tender
     * @param list<string> $waived
     */
    public function collect(
        string $vendorId,
        string $boothId,
        string $today,
        string $sessionId,
        string $amount,
        array $tender,
        array $waived = [],
        string $note = '',
    ): string {
        $figure = $this->figure($vendorId, $boothId, $today);
        if ($figure === null) {
            throw new DatabaseRefusal('a rent receipt cannot exceed the open rent');
        }
        $this->ownsTransaction = !$this->pdo->inTransaction();
        if ($this->ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->clearFees($figure, $today, $waived, $note);
            $journalId = '';
            if ($waived === [] || !$this->unpaid($tender, $amount)) {
                $journalId = $this->take($figure['vendor_id'], $boothId, $today, $sessionId, $amount, $tender);
            }
            $this->commitOwned();

            return $journalId;
        } catch (\Throwable $error) {
            $this->rollbackOwned();
            throw $error;
        }
    }

    /**
     * @return array{
     *     journal_id: string,
     *     number: int,
     *     posted_on: string,
     *     vendor: string,
     *     booth: string,
     *     amount: int,
     *     cash: int,
     *     check_number: string,
     *     tenders: list<array{name: string, amount: int}>
     * }|null
     */
    public function read(string $journalId): ?array
    {
        return $this->slips->read($journalId);
    }

    public function change(string $tendered, int $cash): ?int
    {
        return $this->slips->change($tendered, $cash);
    }

    /**
     * @return list<array{vendor_id: string, code: string, name: string, booth_id: string, booth: string, owes: int, collect: string}>
     */
    public function debtors(string $today): array
    {
        return $this->payouts->debtors($today);
    }

    /**
     * @return list<array{label: string, date: string, amount: int, journal_id: string}>
     */
    public function explain(string $vendorId, int $owed, string $today): array
    {
        return (new RentMoves($this->statements, $this->settings))->explain($vendorId, $owed, $today);
    }

    /**
     * @param array{vendor_id: string, vendor_name: string, booth_id: string, booth: string, owed: int, rent: int, late: int, collect: string} $figure
     * @param list<string> $waived
     */
    private function clearFees(array $figure, string $today, array $waived, string $note): void
    {
        if ($waived === []) {
            return;
        }
        $open = [];
        foreach ($this->explain($figure['vendor_id'], $figure['owed'], $today) as $line) {
            if ($line['journal_id'] !== '') {
                $open[$line['journal_id']] = true;
            }
        }
        $chosen = [];
        foreach ($waived as $id) {
            if (!isset($open[$id])) {
                throw new DatabaseRefusal('Choose a late fee.');
            }
            $chosen[$id] = $id;
        }
        foreach ($chosen as $id) {
            $this->fees->waive($id, $today, $note);
        }
    }

    /**
     * @param array{cash: string, card: string, checks: string, tendered: string, check: string, way?: string} $tender
     */
    private function unpaid(array $tender, string $amount): bool
    {
        foreach (['cash', 'card', 'checks', 'tendered'] as $key) {
            $value = trim($tender[$key] ?? '');
            if ($value !== '' && $value !== '0' && $value !== '0.0' && $value !== '0.00') {
                return false;
            }
        }
        $amount = trim($amount);

        return $amount === '' || $amount === '0' || $amount === '0.0' || $amount === '0.00';
    }

    /**
     * @param array{cash: string, card: string, checks: string, tendered: string, check: string, way?: string} $tender
     */
    private function take(
        string $vendorId,
        string $boothId,
        string $today,
        string $sessionId,
        string $amount,
        array $tender,
    ): string {
        $figure = $this->figure($vendorId, $boothId, $today);
        if ($figure === null) {
            throw new DatabaseRefusal('a rent receipt cannot exceed the open rent');
        }
        if ($sessionId === '') {
            throw new DatabaseRefusal('Open a register to collect');
        }
        $shaped = $this->payments->apply($tender, $amount, $figure['owed']);
        if ($shaped['minor'] < 1) {
            throw new DatabaseRefusal('a rent receipt needs a tender');
        }
        if ($shaped['minor'] > $figure['owed']) {
            throw new DatabaseRefusal('a rent receipt cannot exceed the open rent');
        }
        $lines = $this->posting->tenders($shaped['minor'], $shaped['tender']);

        return $this->posting->postReceipt(
            $figure['vendor_id'],
            $today,
            $sessionId,
            $shaped['minor'],
            $shaped['tender'],
            $lines,
        );
    }

    private function commitOwned(): void
    {
        if (!$this->ownsTransaction) {
            return;
        }
        $this->pdo->commit();
        $this->ownsTransaction = false;
    }

    private function rollbackOwned(): void
    {
        if (!$this->ownsTransaction || !$this->pdo->inTransaction()) {
            $this->ownsTransaction = false;

            return;
        }
        $this->pdo->rollBack();
        $this->ownsTransaction = false;
    }
}
