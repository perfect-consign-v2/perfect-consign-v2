<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;

class SaleCeiling
{
    public function __construct(
        private readonly Statements $statements,
        private readonly Actor $actor,
        private readonly AuditLog $audit,
    ) {
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
        $actor = $this->cover($bps, 'discount_ceiling_bps', $overrideUser, $overridePassword);
        $this->audit->record($actor, $this->actor->role(), 'sale.discount', 'ticket', $entityId);
    }

    public function taxFree(string $overrideUser, string $overridePassword, string $entityId): void
    {
        $actor = $this->cover(1, 'tax_free_ceiling_minor', $overrideUser, $overridePassword);
        $this->audit->record($actor, $this->actor->role(), 'sale.tax_free', 'ticket', $entityId);
    }

    public function second(string $action, string $entityId, string $overrideUser, string $overridePassword): void
    {
        $role = $this->actor->role() !== '' ? $this->actor->role() : 'owner';
        if ($role === 'cashier') {
            $this->audit->record(
                $this->override($overrideUser, $overridePassword, $this->actor->account()),
                $role,
                $action,
                'journal',
                $entityId
            );

            return;
        }
        $account = $this->actor->account();
        $this->audit->record($account !== '' ? $account : 'owner', $role, $action, 'journal', $entityId);
    }

    private function cover(int $amount, string $column, string $overrideUser, string $overridePassword): string
    {
        if ($this->actor->role() === 'owner') {
            $account = $this->actor->account();

            return $account !== '' ? $account : 'owner';
        }
        $account = $this->actor->account();
        $ceiling = $this->ceiling($account, $column);
        if ($amount <= $ceiling) {
            return $account;
        }
        $other = $this->override($overrideUser, $overridePassword, $account);
        if ($amount > $this->ceiling($other, $column)) {
            throw new DatabaseRefusal('that amount is above the ceiling');
        }

        return $other;
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

    private function override(string $username, string $password, string $account): string
    {
        $username = strtolower(trim($username));
        $this->refuseLocked($username);
        $rows = $this->statements->bound(
            'SELECT id::text AS id, password_hash FROM staff WHERE lower(username) = lower(?)',
            [$username]
        );
        $row = $rows[0] ?? null;
        $id = is_array($row) ? RowValue::string($row['id'] ?? null) : '';
        $hash = is_array($row) ? RowValue::string($row['password_hash'] ?? null) : '';
        if ($id === '' || $id === $account || !password_verify($password, $hash)) {
            $this->recordFailure($username);
            throw new DatabaseRefusal('a second person has to sign this');
        }
        $this->clearFailures($username);

        return $id;
    }

    private function refuseLocked(string $username): void
    {
        $slug = $this->actor->slug();
        if ($slug === null || $username === '') {
            throw new DatabaseRefusal('a second person has to sign this');
        }
        $this->statements->change(
            "DELETE FROM platform.override_sign_in_failures
             WHERE failed_at <= clock_timestamp() - interval '15 minutes'",
            []
        );
        $rows = $this->statements->bound(
            "SELECT count(*) AS attempts
             FROM platform.override_sign_in_failures
             WHERE tenant_id = (SELECT id FROM platform.tenants WHERE slug = ?)
               AND username = ?
               AND failed_at > clock_timestamp() - interval '15 minutes'",
            [$slug, $username]
        );
        if (RowValue::integer($rows[0]['attempts'] ?? null) >= 5) {
            throw new DatabaseRefusal('too many sign-in attempts');
        }
    }

    private function recordFailure(string $username): void
    {
        $slug = $this->actor->slug();
        if ($slug === null || preg_match('/^[a-z][a-z0-9-]{0,62}$/', $username) !== 1) {
            return;
        }
        $this->statements->change(
            'INSERT INTO platform.override_sign_in_failures (tenant_id, username)
             SELECT id, ? FROM platform.tenants WHERE slug = ?',
            [$username, $slug]
        );
    }

    private function clearFailures(string $username): void
    {
        $slug = $this->actor->slug();
        if ($slug === null) {
            return;
        }
        $this->statements->change(
            'DELETE FROM platform.override_sign_in_failures
             WHERE tenant_id = (SELECT id FROM platform.tenants WHERE slug = ?)
               AND username = ?',
            [$slug, $username]
        );
    }
}
