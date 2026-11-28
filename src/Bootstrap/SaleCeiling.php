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

    /**
     * A ticket or line discount: the database decides whether a second person
     * must sign and whether their ceiling covers it, and keeps the sign-off;
     * $work writes the discount in the same transaction. An amount off counts
     * as the whole price.
     */
    public function priced(?string $kind, ?int $value, string $overrideUser, string $overridePassword, string $entityId, callable $work): void
    {
        if ($kind === null || ($kind === 'percent' && !is_int($value))) {
            $this->statements->transaction($work);

            return;
        }
        $this->signed('sale.discount', $entityId, $kind === 'percent' ? $value : 10000, $overrideUser, $overridePassword, 'ticket', $work);
    }

    /**
     * A tax-free sale, decided the same way; $work writes the waiver.
     */
    public function waived(?string $reason, string $overrideUser, string $overridePassword, string $entityId, callable $work): void
    {
        if ($reason === null) {
            $this->statements->transaction($work);

            return;
        }
        $this->signed('sale.tax_free', $entityId, 1, $overrideUser, $overridePassword, 'ticket', $work);
    }

    public function note(string $action, string $entityType, string $entityId): void
    {
        $this->requireSubject();
        $this->audit->record($action, $entityType, $entityId);
    }

    /**
     * A void, waive, payout, booth correction or return-tender change. Runs in
     * the caller's transaction, so the sign-off stands with the money.
     */
    public function second(string $action, string $entityId, string $overrideUser, string $overridePassword): void
    {
        $this->signed($action, $entityId, null, $overrideUser, $overridePassword, 'journal', null);
    }

    /**
     * @param (callable(): void)|null $work
     */
    private function signed(string $action, string $entityId, ?int $amount, string $overrideUser, string $overridePassword, string $entityType, ?callable $work): void
    {
        $this->requireSubject();
        $account = $this->actor->account();
        $username = $this->actor->username();
        $authority = null;
        if ($this->needed($action, $account, $username, $amount)) {
            $authority = $this->authority($overrideUser, $overridePassword, $account);
        }
        $this->statements->transaction(function () use ($action, $entityId, $amount, $entityType, $account, $username, $authority, $work): void {
            $this->statements->bound(
                'SELECT stall_sign_off(?, ?, ?::uuid, ?, ?::uuid, ?)::text AS kept',
                [$action, $entityId, $account, $username, $authority?->accountId, $amount]
            );
            $this->audit->record($action, $entityType, $entityId, $authority);
            if ($work !== null) {
                $work();
            }
        });
    }

    private function needed(string $action, string $account, string $username, ?int $amount): bool
    {
        $rows = $this->statements->bound(
            'SELECT stall_sign_off_needed(?, ?::uuid, ?, ?)::text AS needed',
            [$action, $account, $username, $amount]
        );
        $needed = RowValue::string($rows[0]['needed'] ?? null);
        if ($needed !== 'true' && $needed !== 'false') {
            throw new DatabaseRefusal('the signed-in account is missing');
        }

        return $needed === 'true';
    }

    private function requireSubject(): void
    {
        if ($this->actor->role() === '' || $this->actor->account() === '' || $this->actor->username() === '') {
            throw new DatabaseRefusal('the signed-in account is missing');
        }
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
