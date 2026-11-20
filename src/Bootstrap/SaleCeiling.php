<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Scalar\RowValue;

class SaleCeiling
{
    public function __construct(
        private readonly Statements $statements,
        private readonly Actor $actor,
        private readonly JournalAudit $audit,
        private readonly Request $request,
    ) {
    }

    public function required(): bool
    {
        return (new SaleCeilingFlag($this->statements))->required();
    }

    public function priced(?string $kind, ?int $value, string $overrideUser, string $overridePassword, string $entityId): void
    {
        if ($this->actor->role() === '') {
            return;
        }
        if ($kind === 'percent' && is_int($value)) {
            $this->discount($value, $overrideUser, $overridePassword, $entityId);
        }
        if ($kind === 'amount') {
            $this->discount(10000, $overrideUser, $overridePassword, $entityId);
        }
    }

    public function waived(?string $reason, string $overrideUser, string $overridePassword, string $entityId): void
    {
        if ($this->actor->role() === '') {
            return;
        }
        if ($reason !== null) {
            $this->taxFree($overrideUser, $overridePassword, $entityId);
        }
    }

    public function discount(int $bps, string $overrideUser, string $overridePassword, string $entityId): void
    {
        $authority = $this->cover($bps, 'discount_ceiling_bps', $overrideUser, $overridePassword);
        $this->audit->record('sale.discount', 'ticket', $entityId, $authority);
    }

    public function taxFree(string $overrideUser, string $overridePassword, string $entityId): void
    {
        $authority = $this->cover(1, 'tax_free_ceiling_minor', $overrideUser, $overridePassword);
        $this->audit->record('sale.tax_free', 'ticket', $entityId, $authority);
    }

    public function note(string $action, string $entityType, string $entityId): void
    {
        $this->requireSubject();
        $this->audit->record($action, $entityType, $entityId);
    }

    public function second(string $action, string $entityId, string $overrideUser, string $overridePassword): void
    {
        $this->requireSubject();
        $authority = null;
        $role = $this->actor->role();
        if (($role === 'cashier' || $role === 'manager') && $this->required()) {
            $authority = $this->authority($overrideUser, $overridePassword, $this->actor->account());
        }
        $this->audit->record($action, 'journal', $entityId, $authority);
    }

    private function requireSubject(): void
    {
        if ($this->actor->role() === '' || $this->actor->account() === '' || $this->actor->username() === '') {
            throw new DatabaseRefusal('the signed-in account is missing');
        }
    }

    private function cover(int $amount, string $column, string $overrideUser, string $overridePassword): ?AuditAuthority
    {
        $this->requireSubject();
        if ($this->actor->role() === 'owner' || !$this->required()) {
            return null;
        }
        $account = $this->actor->account();
        $ceiling = $this->ceiling($account, $column);
        if ($amount <= $ceiling) {
            return null;
        }
        $authority = $this->authority($overrideUser, $overridePassword, $account);
        if ($amount > $this->ceiling($authority->accountId, $column)) {
            throw new DatabaseRefusal('that amount is above the ceiling');
        }

        return $authority;
    }

    private function ceiling(string $account, string $column): int
    {
        if ($column !== 'discount_ceiling_bps' && $column !== 'tax_free_ceiling_minor') {
            return 0;
        }
        $rows = $this->statements->bound(
            'SELECT ' . $column . ' AS ceiling FROM staff WHERE id = ?::uuid',
            [$account]
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            return 0;
        }

        return RowValue::integer($row['ceiling'] ?? null);
    }

    private function authority(string $username, string $password, string $account): AuditAuthority
    {
        $username = strtolower(trim($username));
        $this->refuseLocked($username);
        $rows = $this->statements->bound(
            "SELECT id::text AS id, username, password_hash
             FROM staff
             WHERE lower(username) = lower(?)
               AND role IN ('manager', 'cashier')",
            [$username]
        );
        $row = $rows[0] ?? null;
        $id = is_array($row) ? RowValue::string($row['id'] ?? null) : '';
        $signed = is_array($row) ? RowValue::string($row['username'] ?? null) : '';
        $hash = is_array($row) ? RowValue::string($row['password_hash'] ?? null) : '';
        if (
            $id === ''
            || $id === $account
            || preg_match('/^[a-z][a-z0-9-]{0,62}$/', $signed) !== 1
            || !password_verify($password, $hash)
        ) {
            $this->recordFailure($username);
            throw new DatabaseRefusal('a second person has to sign this');
        }
        $this->clearFailures($username);

        return new AuditAuthority($id, $signed);
    }

    private function refuseLocked(string $username): void
    {
        if ($this->actor->slug() === null || $username === '') {
            throw new DatabaseRefusal('a second person has to sign this');
        }
        $rows = $this->statements->bound(
            'SELECT stall_override_attempts(?, ?) AS attempts',
            [$username, $this->request->address]
        );
        if (RowValue::integer($rows[0]['attempts'] ?? null) >= 5) {
            throw new DatabaseRefusal('too many sign-in attempts');
        }
    }

    private function recordFailure(string $username): void
    {
        if ($this->actor->slug() === null || preg_match('/^[a-z][a-z0-9-]{0,62}$/', $username) !== 1) {
            return;
        }
        $this->statements->bound(
            'SELECT stall_override_record(?, ?)',
            [$username, $this->request->address]
        );
    }

    private function clearFailures(string $username): void
    {
        if ($this->actor->slug() === null) {
            return;
        }
        $this->statements->bound('SELECT stall_override_clear(?)', [$username]);
    }
}
