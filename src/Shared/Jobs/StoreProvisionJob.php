<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Jobs;

use PDO;
use PerfectConsign\Feature\Signup\SignupSlug;
use PerfectConsign\Schema\TenantProvisioner;
use PerfectConsign\Shared\Mail\MailOutbox;
use RuntimeException;
use Throwable;

/**
 * Builds the store for a confirmed signup. The worker takes the signup into
 * building, then switches to stall_provisioner, which is not a superuser, for
 * the one transaction that creates the store, its owner, and marks the signup
 * ready. The ready letter is filed afterwards as the worker.
 */
final class StoreProvisionJob
{
    public const PURPOSE = 'store-ready';

    public function __construct(
        private readonly PDO $pdo,
        private readonly MailOutbox $outbox,
        private readonly string $sqlDirectory,
        private readonly string $origin,
    ) {
    }

    public function build(string $signupId): void
    {
        $signup = $this->signup($signupId);
        if ($signup === null) {
            throw new RuntimeException('the signup is missing');
        }
        if ($signup['status'] === 'ready') {
            $this->fileReadyLetter($signup);

            return;
        }
        if (!in_array($signup['status'], ['verified', 'provisioning', 'failed'], true)) {
            return;
        }
        if ($signup['status'] !== 'provisioning') {
            $this->markBuilding($signupId);
        }
        try {
            $this->provision($signupId);
        } catch (Throwable $error) {
            $this->markFailed($signupId, $error);
            throw $error;
        }
        $built = $this->signup($signupId);
        if ($built === null || $built['status'] !== 'ready') {
            throw new RuntimeException('the store was built but its signup is not ready');
        }
        $this->fileReadyLetter($built);
    }

    private function provision(string $signupId): void
    {
        if ($this->pdo->exec('SET ROLE stall_provisioner') === false) {
            throw new RuntimeException('the worker could not become the store builder');
        }
        try {
            $statement = $this->pdo->prepare(
                'SELECT email, store_name, password_hash FROM platform.store_signups
                 WHERE id = ?::uuid AND status = \'provisioning\''
            );
            $statement->execute([$signupId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (
                !is_array($row) || !is_string($row['email'] ?? null)
                || !is_string($row['store_name'] ?? null) || !is_string($row['password_hash'] ?? null)
            ) {
                throw new RuntimeException('the signup is not ready to build');
            }
            $email = $row['email'];
            $name = $row['store_name'];
            $hash = $row['password_hash'];
            $slug = $this->freeSlug($name);
            $owner = SignupSlug::username($email);
            (new TenantProvisioner($this->pdo, $this->sqlDirectory))->provision(
                $slug,
                $name,
                function (string $tenantId) use ($signupId, $slug, $owner, $hash, $email, $name): void {
                    $this->finish($signupId, $tenantId, $slug, $owner, $hash, $email, $name);
                }
            );
        } finally {
            $this->pdo->exec('RESET ROLE');
            $this->pdo->exec('RESET search_path');
        }
    }

    private function finish(
        string $signupId,
        string $tenantId,
        string $slug,
        string $owner,
        string $hash,
        string $email,
        string $name,
    ): void {
        $insert = $this->pdo->prepare(
            'INSERT INTO platform.owners (id, tenant_id, username, password_hash, email, email_verified_at)
             VALUES (uuidv7(), ?::uuid, ?, ?, ?, clock_timestamp())'
        );
        $insert->execute([$tenantId, $owner, $hash, $email]);
        $ready = $this->pdo->prepare(
            "UPDATE platform.store_signups
             SET status = 'ready', tenant_id = ?::uuid, slug = ?, provisioned_at = clock_timestamp(),
                 password_hash = NULL
             WHERE id = ?::uuid AND status = 'provisioning'"
        );
        $ready->execute([$tenantId, $slug, $signupId]);
        if ($ready->rowCount() !== 1) {
            throw new RuntimeException('the signup changed while its store was built');
        }
        $audit = $this->pdo->prepare(
            "INSERT INTO platform.admin_audit (admin_email, action, outcome, tenant_id, slug, detail, request_id)
             VALUES (?, 'store.ready', 'ok', ?::uuid, ?, ?::jsonb, 'worker')"
        );
        $audit->execute([$email, $tenantId, $slug, json_encode(['display_name' => $name], JSON_THROW_ON_ERROR)]);
    }

    /**
     * The address from the store name, or the first free one with -2, -3, and so on.
     */
    private function freeSlug(string $name): string
    {
        $base = SignupSlug::fromName($name);
        $taken = $this->pdo->prepare('SELECT 1 FROM platform.tenants WHERE slug = ?');
        for ($number = 1; $number <= 99; $number++) {
            $suffix = $number === 1 ? '' : '-' . $number;
            $slug = rtrim(substr($base, 0, 63 - strlen($suffix)), '-') . $suffix;
            if (SignupSlug::reserved($slug)) {
                continue;
            }
            $taken->execute([$slug]);
            if ($taken->fetchColumn() === false) {
                return $slug;
            }
        }
        throw new RuntimeException('no free store address was found');
    }

    /**
     * @param array{id: string, email: string, store_name: string, status: string, slug: string, ready_mail_id: string} $signup
     */
    private function fileReadyLetter(array $signup): void
    {
        if ($signup['ready_mail_id'] !== '') {
            return;
        }
        if (preg_match('#\Ahttps?://[A-Za-z0-9.-]+(?::[0-9]{1,5})?\z#', $this->origin) !== 1) {
            throw new RuntimeException('STALL_PUBLIC_ORIGIN is required for the store-ready letter');
        }
        $this->pdo->beginTransaction();
        try {
            $body = 'Your store ' . $signup['store_name'] . " is ready.\n\n"
                . "Sign in with your email and the password you chose:\n"
                . $this->origin . '/?email=' . rawurlencode($signup['email']) . "\n\n"
                . 'Your store address is ' . $this->origin . '/' . $signup['slug'] . "/\n";
            if ($signup['slug'] !== SignupSlug::fromName($signup['store_name'])) {
                $body .= "\nThat name was already in use, so a number was added to the address.\n";
            }
            $mailId = $this->outbox->file(
                'platform',
                MailOutbox::MAILBOX_PLATFORM,
                self::PURPOSE,
                $signup['email'],
                'Your store is ready',
                $body,
            );
            $filed = $this->pdo->prepare(
                'UPDATE platform.store_signups SET ready_mail_id = ?::uuid WHERE id = ?::uuid AND ready_mail_id IS NULL'
            );
            $filed->execute([$mailId, $signup['id']]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Records why the build stopped. The build's own error is what the job
     * reports, so a signup that moved on meanwhile does not replace it.
     */
    private function markFailed(string $signupId, Throwable $error): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE platform.store_signups SET status = 'failed', last_error = ?
             WHERE id = ?::uuid AND status = 'provisioning'"
        );
        $statement->execute([substr($error->getMessage(), 0, 500), $signupId]);
    }

    private function markBuilding(string $signupId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE platform.store_signups SET status = 'provisioning', last_error = NULL
             WHERE id = ?::uuid AND status = ANY (ARRAY['verified', 'failed'])"
        );
        $statement->execute([$signupId]);
    }

    /**
     * @return array{id: string, email: string, store_name: string, status: string, slug: string, ready_mail_id: string}|null
     */
    private function signup(string $signupId): ?array
    {
        $statement = $this->pdo->prepare(
            "SELECT id::text AS id, email, store_name, status, coalesce(slug, '') AS slug,
                    coalesce(ready_mail_id::text, '') AS ready_mail_id
             FROM platform.store_signups WHERE id = ?::uuid"
        );
        $statement->execute([$signupId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        foreach (['id', 'email', 'store_name', 'status', 'slug', 'ready_mail_id'] as $key) {
            if (!is_string($row[$key] ?? null)) {
                return null;
            }
        }

        /** @var array{id: string, email: string, store_name: string, status: string, slug: string, ready_mail_id: string} $row */
        return $row;
    }
}
