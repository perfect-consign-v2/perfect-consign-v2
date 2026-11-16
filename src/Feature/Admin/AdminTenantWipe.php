<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

use PerfectConsign\Feature\Backup\BackupStore;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Tenant\TenantRecord;
use DateTimeImmutable;
use PDO;
use RuntimeException;

final class AdminTenantWipe
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AdminAudit $audit,
        private readonly BackupStore $backups,
        private readonly Request $request,
    ) {
    }

    /**
     * @param array{id: string, email: string, version: string} $admin
     */
    public function wipe(
        array $admin,
        TenantRecord $tenant,
        string $confirmation,
        string $secondEmail,
        string $secondPassword,
    ): void {
        $this->requireSlug($admin, $tenant, $confirmation);
        $email = strtolower(trim($secondEmail));
        if ($this->usableSecondCredentials($email, $secondPassword) && $this->wipeLocked($email)) {
            $this->refuse($admin, $tenant, 'second_admin_locked');
            throw new \InvalidArgumentException('Too many tries. Try again in a few minutes.');
        }
        $second = $this->secondAdmin($admin['id'], $email, $secondPassword);
        if ($second === null) {
            if ($this->usableSecondCredentials($email, $secondPassword)) {
                $this->recordWipeFailure($email);
            }
            $this->refuse($admin, $tenant, 'second_admin');
            throw new \InvalidArgumentException('A second platform admin must sign this deletion.');
        }
        $this->clearWipeFailures($second['email']);
        $this->requireRecentBackup($admin, $tenant);
        $this->drop($admin, $tenant, $second['email']);
    }

    /**
     * @param array{id: string, email: string, version: string} $admin
     */
    private function requireSlug(array $admin, TenantRecord $tenant, string $confirmation): void
    {
        if ($confirmation === $tenant->slug) {
            return;
        }
        $this->refuse($admin, $tenant, 'slug_mismatch');
        throw new \InvalidArgumentException('Type the store slug exactly to confirm deletion.');
    }

    /**
     * @param array{id: string, email: string, version: string} $admin
     */
    private function requireRecentBackup(array $admin, TenantRecord $tenant): void
    {
        $latest = $this->backups->latest($tenant->id);
        if ($latest !== null && $latest >= new DateTimeImmutable('-7 days')) {
            return;
        }
        $this->refuse($admin, $tenant, 'backup_required');
        throw new \InvalidArgumentException(
            'Download an owner backup for this store within the last seven days before deleting it.'
        );
    }

    /**
     * @param array{id: string, email: string, version: string} $admin
     */
    private function drop(array $admin, TenantRecord $tenant, string $secondEmail): void
    {
        $statement = $this->pdo->prepare('SELECT platform.wipe_tenant(?)');
        try {
            $statement->execute([$tenant->slug]);
        } catch (\PDOException $error) {
            $this->audit->record(
                $admin['id'],
                $admin['email'],
                'tenant.delete',
                'failed',
                $tenant->id,
                $tenant->slug,
                [
                    'display_name' => $tenant->displayName,
                    'second_admin' => $secondEmail,
                    'error' => $error->getMessage(),
                ],
            );
            throw new RuntimeException($error->getMessage(), 0, $error);
        }
        $this->audit->record(
            $admin['id'],
            $admin['email'],
            'tenant.delete',
            'ok',
            $tenant->id,
            $tenant->slug,
            [
                'display_name' => $tenant->displayName,
                'second_admin' => $secondEmail,
            ],
        );
    }

    /**
     * @param array{id: string, email: string, version: string} $admin
     */
    private function refuse(array $admin, TenantRecord $tenant, string $reason): void
    {
        $this->audit->record(
            $admin['id'],
            $admin['email'],
            'tenant.delete_refused',
            'refused',
            $tenant->id,
            $tenant->slug,
            ['reason' => $reason],
        );
    }

    /**
     * @return array{id: string, email: string}|null
     */
    private function secondAdmin(string $actingId, string $email, string $password): ?array
    {
        $email = strtolower(trim($email));
        if (!$this->usableSecondCredentials($email, $password)) {
            return null;
        }
        $row = $this->adminRow($email);
        if ($row === null || $row['id'] === $actingId) {
            return null;
        }
        if (!password_verify($password, $row['hash'])) {
            return null;
        }

        return ['id' => $row['id'], 'email' => strtolower($row['email'])];
    }

    private function usableSecondCredentials(string $email, string $password): bool
    {
        return preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email) === 1
            && strlen($email) <= 200
            && $password !== '';
    }

    /**
     * @return array{id: string, email: string, hash: string}|null
     */
    private function adminRow(string $email): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id::text AS id, email, password_hash
             FROM platform.admins
             WHERE lower(email) = lower(?)'
        );
        $statement->execute([$email]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        $id = $row['id'] ?? null;
        $hash = $row['password_hash'] ?? null;
        $storedEmail = $row['email'] ?? null;
        if (!is_string($id) || !is_string($hash) || !is_string($storedEmail)) {
            return null;
        }

        return ['id' => $id, 'email' => $storedEmail, 'hash' => $hash];
    }

    private function wipeLocked(string $email): bool
    {
        $checks = [
            [
                "SELECT count(*)
                 FROM platform.admin_wipe_failures
                 WHERE email = ?
                   AND client_address = ?
                   AND failed_at > clock_timestamp() - interval '15 minutes'",
                [$email, $this->request->address],
                5,
            ],
            [
                "SELECT count(*)
                 FROM platform.admin_wipe_failures
                 WHERE client_address = ?
                   AND failed_at > clock_timestamp() - interval '15 minutes'",
                [$this->request->address],
                40,
            ],
        ];
        foreach ($checks as [$sql, $params, $limit]) {
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            $count = $statement->fetchColumn();
            $recent = 0;
            if (is_int($count)) {
                $recent = $count;
            } elseif (is_string($count) && preg_match('/^\d+$/', $count) === 1) {
                $recent = (int) $count;
            }
            if ($recent >= $limit) {
                return true;
            }
        }

        return false;
    }

    private function recordWipeFailure(string $email): void
    {
        $forEmail = $this->pdo->prepare(
            "DELETE FROM platform.admin_wipe_failures
             WHERE email = ?
               AND failed_at <= clock_timestamp() - interval '15 minutes'"
        );
        $forEmail->execute([$email]);
        $stale = $this->pdo->prepare(
            "DELETE FROM platform.admin_wipe_failures
             WHERE failed_at <= clock_timestamp() - interval '15 minutes'"
        );
        $stale->execute([]);
        $failed = $this->pdo->prepare(
            'INSERT INTO platform.admin_wipe_failures (email, client_address) VALUES (?, ?)'
        );
        $failed->execute([$email, $this->request->address]);
    }

    private function clearWipeFailures(string $email): void
    {
        $clear = $this->pdo->prepare(
            'DELETE FROM platform.admin_wipe_failures WHERE email = ? AND client_address = ?'
        );
        $clear->execute([$email, $this->request->address]);
    }
}
