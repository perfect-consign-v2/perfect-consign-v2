<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Payout;

use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PDOException;

final class PayoutOnce
{
    public function __construct(
        private readonly PayoutRunStore $runs,
        private readonly PayoutSettler $settler,
        private readonly PayoutDisburser $disburser,
        private readonly Statements $statements,
        private readonly CheckWords $words,
        private readonly RentShortfall $shortfall,
    ) {
    }

    public function pay(
        string $from,
        string $to,
        string $rent,
        string $today,
        string $tender,
        ?string $sessionId,
    ): void {
        try {
            $this->statements->transaction(function () use ($from, $to, $rent, $today, $tender, $sessionId): void {
                $runId = $this->runs->ensureDraft($from, $to, $rent, $today, $tender);
                $this->runs->review($runId);
                $this->settler->close($runId, $today);
                $this->disburser->pay($runId, $today, $sessionId);
            });
        } catch (PDOException $error) {
            throw DatabaseRefusal::fromPdo($error);
        }
    }

    /**
     * @return array{id: string, tender: string, status: string}|null
     */
    public function finishedRun(string $from, string $to, string $rent): ?array
    {
        foreach ($this->runs->history() as $run) {
            $found = $this->matchingRun($run, $from, $to, $rent);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $run
     * @return array{id: string, tender: string, status: string}|null
     */
    private function matchingRun(array $run, string $from, string $to, string $rent): ?array
    {
        $status = $run['status'] ?? null;
        if (!is_string($status) || ($status !== 'paid' && $status !== 'settled')) {
            return null;
        }
        if (!$this->sameWindow($run, $from, $to, $rent)) {
            return null;
        }
        $id = $run['id'] ?? null;
        if (!is_string($id) || $id === '') {
            return null;
        }

        return [
            'id' => $id,
            'tender' => is_string($run['tender'] ?? null) ? $run['tender'] : '',
            'status' => $status,
        ];
    }

    /**
     * @param array<string, mixed> $run
     */
    private function sameWindow(array $run, string $from, string $to, string $rent): bool
    {
        return $this->day($run['sales_from'] ?? null) === $from
            && $this->day($run['sales_to'] ?? null) === $to
            && $this->day($run['rent_month'] ?? null) === $rent;
    }

    /**
     * @return list<array{number: string, name: string, code: string, booth: string, amount: int, words: string}>
     */
    public function savedChecks(string $runId): array
    {
        $checks = [];
        foreach ($this->runs->envelopes($runId) as $row) {
            if ($row['check'] <= 0 || $row['check_number'] === '' || isset($checks[$row['vendor_id']])) {
                continue;
            }
            $checks[$row['vendor_id']] = [
                'number' => $row['check_number'],
                'name' => $row['name'],
                'code' => $row['code'],
                'booth' => $row['booth'],
                'amount' => $row['check'],
                'words' => $this->words->words($row['check']),
            ];
        }

        return array_values($checks);
    }

    public function numberChecks(string $runId, int $start): void
    {
        $this->disburser->numberChecks($runId, $start);
    }

    /**
     * @return list<array{vendor_id: string, code: string, name: string, booth: string, mode: string, prior: int, charged: int, paid: int, payable: int, rent: int, monthRent: int, check: int, owes: int, settled: int, adjustment: int, note: string}>
     */
    public function savedPack(string $runId): array
    {
        $pack = [];
        foreach ($this->runs->stored($runId) as $row) {
            if ($row['mode'] === 'pay') {
                $pack[] = $row;
            }
        }

        return $this->shortfall->attach($pack, $this->day($this->runs->run($runId)['rent_month']));
    }

    /**
     * @return list<array{vendor_id: string, code: string, name: string, booth: string, mode: string, prior: int, charged: int, paid: int, payable: int, rent: int, monthRent: int, check: int, owes: int, settled: int, adjustment: int, note: string}>
     */
    public function openPack(string $from, string $to, string $rent, string $today): array
    {
        $draft = $this->runs->openDraft($from, $to, $rent);
        $pack = $draft === null
            ? $this->runs->preview($from, $to, $rent, $today)
            : $this->runs->pack($draft);

        return $this->shortfall->attach($pack, $rent);
    }

    private function day(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            return '';
        }

        return substr($value, 0, 10);
    }
}
