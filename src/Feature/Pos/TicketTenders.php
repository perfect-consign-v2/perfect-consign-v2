<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pos;

use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Feature\Register\RegisterStore;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\PostedLines;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

final class TicketTenders
{
    /** @var list<string> */
    private const ROLES = ['cash', 'card', 'checks', 'gift_certificate', 'gift_card', 'store_credit', 'vendor_draw'];

    /** @var array<string, string> */
    private const STORED = [
        'gift_certificate' => 'gift_certificate',
        'gift_card' => 'gift_card',
        'store_credit' => 'store_credit',
    ];

    public function __construct(
        private readonly Statements $statements,
        private readonly LedgerStore $ledger,
        private readonly RegisterStore $registers,
        private readonly PayoutStore $payouts,
    ) {
    }

    /**
     * @return list<array{role: string, amount: int, reference: string}>
     */
    public function list(string $ticketId): array
    {
        if ($ticketId === '') {
            return [];
        }
        $rows = $this->statements->bound(
            'SELECT role, amount_minor, reference
             FROM ticket_tenders
             WHERE ticket_id = ?::uuid
             ORDER BY role',
            [$ticketId]
        );
        $held = [];
        foreach ($rows as $row) {
            $held[] = [
                'role' => RowValue::string($row['role'] ?? null),
                'amount' => RowValue::integer($row['amount_minor'] ?? null),
                'reference' => RowValue::string(is_string($row['reference'] ?? null) ? $row['reference'] : ''),
            ];
        }

        return $held;
    }

    public function apply(string $ticketId, string $role, int $amount, string $reference, int $due): void
    {
        $this->openRole($ticketId, $role, $amount);
        $reference = $this->reference($role, $reference);
        $this->room($ticketId, $role, $amount, $due);
        $this->cap($role, $reference, $amount);
        $this->statements->change(
            'INSERT INTO ticket_tenders (ticket_id, role, amount_minor, reference)
             VALUES (?::uuid, ?, ?, ?)',
            [$ticketId, $role, $amount, $reference === '' ? null : $reference]
        );
    }

    public function remove(string $ticketId, string $role): void
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new DatabaseRefusal('a sale debits each tender once');
        }
        $this->statements->change(
            'DELETE FROM ticket_tenders WHERE ticket_id = ?::uuid AND role = ?',
            [$ticketId, $role]
        );
    }

    /**
     * @return array{
     *     lines: list<array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}>,
     *     sum: int,
     *     check: ?string,
     *     cash: int
     * }|null
     */
    public function split(string $ticketId, int $due, int $already = 0): ?array
    {
        $held = $this->list($ticketId);
        if ($held === []) {
            if ($already === $due && $already > 0) {
                return ['lines' => [], 'sum' => 0, 'check' => null, 'cash' => 0];
            }

            return null;
        }
        $lines = [];
        $sum = 0;
        $cash = 0;
        $nonCash = 0;
        $check = null;
        foreach ($held as $tender) {
            $built = $this->line($tender['role'], $tender['amount'], $tender['reference']);
            $lines[] = $built['line'];
            $sum += $tender['amount'];
            if ($tender['role'] === 'cash') {
                $cash = $tender['amount'];
            } else {
                $nonCash += $tender['amount'];
            }
            if ($tender['role'] === 'checks') {
                $check = $tender['reference'];
            }
        }
        $this->covered($due - $already, $cash, $nonCash);

        return [
            'lines' => $lines,
            'sum' => $sum,
            'check' => $check,
            'cash' => $cash,
        ];
    }

    private function openRole(string $ticketId, string $role, int $amount): void
    {
        if ($ticketId === '' || !in_array($role, self::ROLES, true) || $amount < 1) {
            throw new DatabaseRefusal($ticketId === '' ? 'there is no open ticket' : 'a sale debits each tender once');
        }
    }

    private function room(string $ticketId, string $role, int $amount, int $due): void
    {
        $sum = $amount;
        foreach ($this->list($ticketId) as $tender) {
            if ($tender['role'] === $role) {
                throw new DatabaseRefusal('a sale debits each tender once');
            }
            $sum += $tender['amount'];
        }
        if ($sum > $due + 2) {
            throw new DatabaseRefusal('a sale debits the sold prices plus tax');
        }
    }

    private function cap(string $role, string $reference, int $amount): void
    {
        if ($role === 'vendor_draw' && $amount > $this->payouts->openPayout($reference)['payable']) {
            throw new DatabaseRefusal('a vendor draw is limited to the open payable');
        }
    }

    private function covered(int $due, int $cash, int $nonCash): void
    {
        if ($cash === 0 && $nonCash !== $due) {
            throw new DatabaseRefusal('the tenders do not cover the amount due');
        }
        if ($cash === 0) {
            return;
        }
        $exact = $due - $nonCash;
        if ($exact < 0 || $cash !== $exact) {
            throw new DatabaseRefusal('the tenders do not cover the amount due');
        }
    }

    private function reference(string $role, string $reference): string
    {
        $reference = trim($reference);
        if ($role === 'checks' || isset(self::STORED[$role])) {
            if ($reference === '' || strlen($reference) > 40) {
                throw new DatabaseRefusal($role === 'checks' ? 'a check sale needs a check number' : 'a stored value number is required');
            }

            return $reference;
        }
        if ($role === 'vendor_draw') {
            if (preg_match('/^[0-9a-fA-F-]{36}$/', $reference) !== 1) {
                throw new DatabaseRefusal('Choose a vendor.');
            }

            return $reference;
        }

        return '';
    }

    /**
     * @return array{line: array{account_id: string, debit: int, credit: int, subledger_type: ?string, subledger_ref: ?string}}
     */
    private function line(string $role, int $amount, string $reference): array
    {
        if ($role === 'vendor_draw') {
            return [
                'line' => PostedLines::entry($this->ledger->roleAccount('vendor_payable'), $amount, 0, 'vendor', $reference),
            ];
        }
        $kind = self::STORED[$role] ?? null;
        if ($kind !== null) {
            return [
                'line' => PostedLines::entry(
                    $this->ledger->roleAccount($role),
                    $amount,
                    0,
                    $kind,
                    $this->registers->storedValueId($kind, $reference)
                ),
            ];
        }

        return [
            'line' => PostedLines::entry($this->ledger->roleAccount($role), $amount, 0, null, null),
        ];
    }
}
