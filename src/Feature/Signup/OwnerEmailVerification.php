<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Signup;

use PerfectConsign\Feature\Admin\AdminAudit;
use PerfectConsign\Shared\Mail\PlatformMailbox;
use PDO;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class OwnerEmailVerification
{
    private const EXPIRY_MINUTES = 30;

    public function __construct(
        private readonly PDO $pdo,
        private readonly PlatformMailbox $platformMail,
        private readonly AdminAudit $audit,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function send(string $ownerId, string $email, string $origin): void
    {
        if (preg_match('#\Ahttps?://[A-Za-z0-9.-]+(?::[0-9]{1,5})?\z#', $origin) !== 1) {
            $this->logger->error('signup verify origin is missing');
            throw new RuntimeException('The confirmation email could not be sent.');
        }
        if (!$this->platformMail->ready()) {
            $this->logger->error('signup verify mailbox is not set up');
            throw new RuntimeException('The confirmation email could not be sent.');
        }
        $token = $this->issue($ownerId);
        $body = "Confirm your Perfect Consign store email.\n\n"
            . "Open this link within 30 minutes:\n"
            . $origin . '/signup/verify/' . $token . "\n\n"
            . "If you did not create a store, you can ignore this message.\n";
        try {
            $this->platformMail->send(
                $email,
                'Confirm your store email',
                $body,
                'signup verify mail was refused',
            );
        } catch (\Throwable $error) {
            $this->logger->error('signup verify mail was refused', [
                'message' => $error->getMessage(),
            ]);
            throw new RuntimeException('The confirmation email could not be sent.', 0, $error);
        }
    }

    /**
     * @return array{email: string, slug: string}|null
     */
    public function confirm(string $token): ?array
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return null;
        }
        $this->pdo->beginTransaction();
        try {
            $pending = $this->openPending($token);
            if ($pending === null) {
                $this->pdo->rollBack();

                return null;
            }
            if (!$this->consumeVerification($pending['id'], $pending['owner_id'])) {
                $this->pdo->rollBack();

                return null;
            }
            $this->audit->record(
                null,
                $pending['email'],
                'store.email_verified',
                'ok',
                $pending['tenant_id'],
                $pending['slug'],
                [],
            );
            $this->pdo->commit();

            return ['email' => $pending['email'], 'slug' => $pending['slug']];
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * @return array{id: string, owner_id: string, email: string, slug: string, tenant_id: string}|null
     */
    private function openPending(string $token): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT v.id::text AS id, v.owner_id::text AS owner_id, o.email, t.slug, t.id::text AS tenant_id
             FROM platform.owner_email_verifications v
             INNER JOIN platform.owners o ON o.id = v.owner_id
             INNER JOIN platform.tenants t ON t.id = o.tenant_id
             WHERE v.token_hash = ?
               AND v.used_at IS NULL
               AND v.expires_at > clock_timestamp()
             FOR UPDATE OF v'
        );
        $statement->execute([hash('sha256', $token)]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            return null;
        }
        $id = $row['id'] ?? null;
        $ownerId = $row['owner_id'] ?? null;
        $email = $row['email'] ?? null;
        $slug = $row['slug'] ?? null;
        $tenantId = $row['tenant_id'] ?? null;
        if (
            !is_string($id)
            || !is_string($ownerId)
            || !is_string($email)
            || !is_string($slug)
            || !is_string($tenantId)
        ) {
            return null;
        }

        return [
            'id' => $id,
            'owner_id' => $ownerId,
            'email' => $email,
            'slug' => $slug,
            'tenant_id' => $tenantId,
        ];
    }

    private function consumeVerification(string $id, string $ownerId): bool
    {
        $mark = $this->pdo->prepare(
            'UPDATE platform.owner_email_verifications
             SET used_at = clock_timestamp()
             WHERE id = ?::uuid AND used_at IS NULL'
        );
        $mark->execute([$id]);
        if ($mark->rowCount() !== 1) {
            return false;
        }
        $verify = $this->pdo->prepare(
            'UPDATE platform.owners
             SET email_verified_at = COALESCE(email_verified_at, clock_timestamp())
             WHERE id = ?::uuid'
        );
        $verify->execute([$ownerId]);

        return true;
    }

    private function issue(string $ownerId): string
    {
        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->pdo->beginTransaction();
        try {
            $expire = $this->pdo->prepare(
                'UPDATE platform.owner_email_verifications
                 SET used_at = clock_timestamp()
                 WHERE owner_id = ?::uuid
                   AND used_at IS NULL'
            );
            $expire->execute([$ownerId]);
            $insert = $this->pdo->prepare(
                'INSERT INTO platform.owner_email_verifications (owner_id, token_hash, expires_at)
                 VALUES (?::uuid, ?, clock_timestamp() + make_interval(mins => ?))'
            );
            $insert->execute([$ownerId, hash('sha256', $raw), self::EXPIRY_MINUTES]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }

        return $raw;
    }

}
