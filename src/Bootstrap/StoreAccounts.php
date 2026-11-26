<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PerfectConsign\Shared\Tenant\TenantRecord;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * The owner or staff account behind a store username: checks the password
 * inside the sign-in gate, then opens the session once the gate lets it in.
 */
final class StoreAccounts
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly StaffSignIn $staff,
        private readonly LoginSession $sessions,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function check(TenantRecord $tenant, string $username, string $password): StoreAccountMatch
    {
        $owner = $this->owner($tenant->id, $username);
        if ($owner === null) {
            [$found, $staff] = $this->staff->check($tenant, $username, $password);

            return new StoreAccountMatch($found, null, $staff);
        }
        $right = $owner['can_sign_in'] && password_verify($password, $owner['hash']);

        return new StoreAccountMatch($right ? SignInCheck::Right : SignInCheck::Wrong, $owner, null);
    }

    public function open(TenantRecord $tenant, string $username, StoreAccountMatch $match): void
    {
        if ($match->staff !== null) {
            $this->staff->open($tenant, $username, $match->staff);

            return;
        }
        $owner = $match->owner;
        if ($owner === null) {
            throw new \LogicException('a sign-in passed without an account');
        }
        if (!$owner['verified']) {
            $this->logger->info('owner sign-in refused unverified email', ['slug' => $tenant->slug]);
            throw new SignInUnverified('Confirm your email before signing in.');
        }
        $this->sessions->open($tenant->slug, $tenant->id, $username, 'owner', $owner['id'], $owner['version'], '', '', $username);
    }

    /**
     * @return array{id: string, hash: string, version: string, verified: bool, can_sign_in: bool}|null
     */
    private function owner(string $tenantId, string $username): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id::text AS id, password_hash, credentials_version, email_verified_at, can_sign_in
             FROM platform.owners
             WHERE tenant_id = ?::uuid AND username = ?'
        );
        $statement->execute([$tenantId, $username]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        $id = $row['id'] ?? null;
        $hash = $row['password_hash'] ?? null;
        $version = $row['credentials_version'] ?? null;
        $canSignIn = $this->flag($row['can_sign_in'] ?? null);
        if (is_int($version)) {
            $version = (string) $version;
        }
        if (
            !is_string($id)
            || !is_string($hash)
            || !is_string($version)
            || ctype_digit($version) !== true
            || $canSignIn === null
        ) {
            return null;
        }

        return [
            'id' => $id,
            'hash' => $hash,
            'version' => $version,
            'verified' => $row['email_verified_at'] !== null,
            'can_sign_in' => $canSignIn,
        ];
    }

    private function flag(mixed $value): ?bool
    {
        return match (true) {
            is_bool($value) => $value,
            $value === 't', $value === 'true', $value === 1, $value === '1' => true,
            $value === 'f', $value === 'false', $value === 0, $value === '0' => false,
            default => null,
        };
    }
}
