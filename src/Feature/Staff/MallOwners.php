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
        $this->asOwner($tenant, function () use ($tenant, $ownerId, $canSignIn): void {
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
        });
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

    public function add(TenantRecord $tenant, string $username, string $password, string $email): string
    {
        $username = strtolower(trim($username));
        if (preg_match('/^[a-z][a-z0-9-]{0,62}$/', $username) !== 1) {
            throw new \InvalidArgumentException('Use a sign-in name of letters, numbers, and hyphens.');
        }
        $hash = $this->passwords->hash($password);
        $address = $this->addresses->parse($email);
        $id = $this->asOwner($tenant, function () use ($tenant, $username, $hash, $address): mixed {
            try {
                $statement = $this->pdo->prepare(
                    'SELECT platform.add_mall_owner(?::uuid, ?, ?, ?) AS id'
                );
                $statement->execute([$tenant->id, $username, $hash, $address]);

                return $statement->fetchColumn();
            } catch (PDOException $error) {
                throw DatabaseRefusal::fromPdo($error);
            }
        });
        if (!is_string($id) || preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1) {
            throw new \RuntimeException('the owner row was not stored');
        }

        return $id;
    }

    public function replaceSignIn(TenantRecord $tenant, string $id, string $password, string $email): void
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1) {
            throw new \InvalidArgumentException('Choose an owner.');
        }
        $hash = $this->passwords->hash($password);
        $address = $this->addresses->parse($email);
        $this->asOwner($tenant, function () use ($tenant, $id, $hash, $address): void {
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
        });
    }

    public function remove(TenantRecord $tenant, string $ownerId, string $actorOwnerId): void
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $ownerId) !== 1) {
            throw new \InvalidArgumentException('Choose an owner.');
        }
        if ($ownerId === $actorOwnerId) {
            throw new \InvalidArgumentException('You cannot remove your own owner sign-in.');
        }
        $this->asOwner($tenant, function () use ($tenant, $ownerId): void {
            try {
                $statement = $this->pdo->prepare(
                    'SELECT platform.remove_mall_owner(?::uuid, ?::uuid)'
                );
                $statement->execute([$tenant->id, $ownerId]);
            } catch (PDOException $error) {
                throw DatabaseRefusal::fromPdo($error);
            }
        });
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function asOwner(TenantRecord $tenant, callable $work): mixed
    {
        $this->roles->reset();
        try {
            return $this->guarded($work);
        } finally {
            $this->roles->assume($tenant->role);
        }
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function guarded(callable $work): mixed
    {
        if (!$this->pdo->inTransaction()) {
            return $work();
        }
        if ($this->pdo->exec('SAVEPOINT mall_owner') === false) {
            throw new \RuntimeException('the owner change could not start');
        }
        try {
            $result = $work();
        } catch (\Throwable $error) {
            $this->undoOwnerChange($error);

            throw $error;
        }
        if ($this->pdo->exec('RELEASE SAVEPOINT mall_owner') === false) {
            throw new \RuntimeException('the owner change could not finish');
        }

        return $result;
    }

    private function undoOwnerChange(\Throwable $error): void
    {
        if ($this->pdo->exec('ROLLBACK TO SAVEPOINT mall_owner') === false) {
            throw new \RuntimeException('the owner change could not be undone', 0, $error);
        }
        if ($this->pdo->exec('RELEASE SAVEPOINT mall_owner') === false) {
            throw new \RuntimeException('the owner change could not be undone', 0, $error);
        }
    }
}
