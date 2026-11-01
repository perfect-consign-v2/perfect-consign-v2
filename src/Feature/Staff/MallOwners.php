<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Staff;

use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Feature\Password\PasswordChoice;
use PerfectConsign\Feature\Password\RecoveryAddress;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Scalar\RowValue;
use PerfectConsign\Shared\Tenant\TenantRecord;
use PDO;
use PDOException;

final class MallOwners
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly DatabaseRole $roles,
        private readonly RecoveryAddress $addresses,
        private readonly PasswordChoice $passwords,
    ) {
    }

    /**
     * @return list<array{id: string, username: string, email: string, can_sign_in: bool}>
     */
    public function list(TenantRecord $tenant): array
    {
        $this->roles->reset();
        try {
            $statement = $this->pdo->prepare(
                'SELECT id::text AS id, username, email, can_sign_in
                 FROM platform.owners
                 WHERE tenant_id = ?::uuid
                 ORDER BY lower(username)'
            );
            $statement->execute([$tenant->id]);
            $people = [];
            while ($row = $statement->fetch()) {
                if (!is_array($row)) {
                    continue;
                }
                $people[] = [
                    'id' => RowValue::string($row['id'] ?? null),
                    'username' => RowValue::string($row['username'] ?? null),
                    'email' => RowValue::optionalString($row['email'] ?? null) ?? '',
                    'can_sign_in' => $this->flag($row['can_sign_in'] ?? null),
                ];
            }

            return $people;
        } finally {
            $this->roles->assume($tenant->role);
        }
    }

    public function setCanSignIn(TenantRecord $tenant, string $ownerId, bool $canSignIn): void
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $ownerId) !== 1) {
            throw new \InvalidArgumentException('Choose an owner.');
        }
        $this->roles->reset();
        try {
            if (!$canSignIn) {
                $this->refuseLastActiveSuspend($tenant->id, $ownerId);
            }
            $sql = $canSignIn
                ? 'UPDATE platform.owners
                   SET can_sign_in = true
                   WHERE id = ?::uuid AND tenant_id = ?::uuid'
                : 'UPDATE platform.owners
                   SET can_sign_in = false, credentials_version = credentials_version + 1
                   WHERE id = ?::uuid AND tenant_id = ?::uuid';
            $statement = $this->pdo->prepare($sql);
            $statement->execute([$ownerId, $tenant->id]);
            if ($statement->rowCount() !== 1) {
                throw new \InvalidArgumentException('Choose an owner.');
            }
        } finally {
            $this->roles->assume($tenant->role);
        }
    }

    private function refuseLastActiveSuspend(string $tenantId, string $ownerId): void
    {
        $active = $this->pdo->prepare(
            'SELECT count(*)::integer
             FROM platform.owners
             WHERE tenant_id = ?::uuid AND can_sign_in = true AND id <> ?::uuid'
        );
        $active->execute([$tenantId, $ownerId]);
        if ((int) $active->fetchColumn() < 1) {
            throw new \InvalidArgumentException('Keep at least one active owner on the mall.');
        }
    }

    private function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }

    public function add(TenantRecord $tenant, string $username, string $password, string $email): void
    {
        $username = strtolower(trim($username));
        if (preg_match('/^[a-z][a-z0-9-]{0,62}$/', $username) !== 1) {
            throw new \InvalidArgumentException('Use a sign-in name of letters, numbers, and hyphens.');
        }
        $hash = $this->passwords->hash($password);
        $address = $this->addresses->parse($email);
        $this->roles->reset();
        try {
            $statement = $this->pdo->prepare(
                'SELECT platform.add_mall_owner(?::uuid, ?, ?, ?)'
            );
            $statement->execute([$tenant->id, $username, $hash, $address]);
        } catch (PDOException $error) {
            throw DatabaseRefusal::fromPdo($error);
        } finally {
            $this->roles->assume($tenant->role);
        }
    }

    public function replaceSignIn(TenantRecord $tenant, string $id, string $password, string $email): void
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1) {
            throw new \InvalidArgumentException('Choose an owner.');
        }
        $hash = $this->passwords->hash($password);
        $address = $this->addresses->parse($email);
        $this->roles->reset();
        try {
            $statement = $this->pdo->prepare(
                'UPDATE platform.owners
                 SET password_hash = ?, email = ?, credentials_version = credentials_version + 1
                 WHERE id = ?::uuid AND tenant_id = ?::uuid'
            );
            try {
                $statement->execute([$hash, $address, $id, $tenant->id]);
            } catch (PDOException $error) {
                $state = $error->errorInfo[0] ?? '';
                if ($state === '23505') {
                    throw new \InvalidArgumentException('That email is already used on another sign-in.');
                }
                throw DatabaseRefusal::fromPdo($error);
            }
            if ($statement->rowCount() !== 1) {
                throw new \InvalidArgumentException('Choose an owner.');
            }
        } finally {
            $this->roles->assume($tenant->role);
        }
    }

    public function remove(TenantRecord $tenant, string $ownerId, string $actorOwnerId): void
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $ownerId) !== 1) {
            throw new \InvalidArgumentException('Choose an owner.');
        }
        if ($ownerId === $actorOwnerId) {
            throw new \InvalidArgumentException('You cannot remove your own owner sign-in.');
        }
        $this->roles->reset();
        try {
            $statement = $this->pdo->prepare(
                'SELECT platform.remove_mall_owner(?::uuid, ?::uuid)'
            );
            $statement->execute([$tenant->id, $ownerId]);
        } catch (PDOException $error) {
            throw DatabaseRefusal::fromPdo($error);
        } finally {
            $this->roles->assume($tenant->role);
        }
    }
}
