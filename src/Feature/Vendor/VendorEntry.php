<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Vendor;

use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Mail\LetterMailbox;
use PerfectConsign\Shared\Mail\MailOutbox;
use PerfectConsign\Shared\Scalar\RowValue;
use PerfectConsign\Shared\Tenant\TenantRecord;
use PDO;
use Psr\Log\LoggerInterface;

final class VendorEntry
{
    public const SENT = 'If that email can open a consignor page, the link is on its way. The link works for 30 minutes.';

    public function __construct(
        private readonly PDO $pdo,
        private readonly DatabaseRole $roles,
        private readonly Statements $statements,
        private readonly LetterMailbox $mailbox,
        private readonly MailOutbox $outbox,
        private readonly LoggerInterface $logger,
        private readonly Request $request,
    ) {
    }

    public function request(TenantRecord $tenant, string $email, string $origin): void
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return;
        }
        $token = $this->mint($tenant, $email, $origin);
        if ($token === null) {
            return;
        }
        $this->asPoster($tenant);
        try {
            if (!$this->mailbox->ready()) {
                $this->logger->error('consignor entry mailbox is not set up', ['slug' => $tenant->slug]);

                return;
            }
            $this->outbox->file(
                $tenant->slug,
                MailOutbox::MAILBOX_MALL,
                'consignor entry mail was refused',
                $email,
                'Your consignor link',
                $origin . '/' . $tenant->slug . '/consignor/enter/' . $token,
            );
        } catch (\Throwable $error) {
            $this->logger->error('consignor entry mail could not be filed', [
                'slug' => $tenant->slug,
                'message' => $error->getMessage(),
            ]);
        } finally {
            $this->roles->reset();
        }
    }

    public function open(TenantRecord $tenant, string $hash): string
    {
        $this->asEntry($tenant);
        try {
            $rows = $this->statements->bound(
                'SELECT stall_vendor_open(?) AS vendor_id',
                [$hash]
            );
        } finally {
            $this->roles->reset();
        }
        $vendorId = $rows[0]['vendor_id'] ?? null;

        return is_string($vendorId) ? $vendorId : '';
    }

    public function isActive(TenantRecord $tenant, string $vendorId): bool
    {
        if (preg_match('/^[0-9a-fA-F-]{36}$/', $vendorId) !== 1) {
            return false;
        }
        $this->asEntry($tenant);
        try {
            $rows = $this->statements->bound(
                'SELECT active FROM vendors WHERE id = ?::uuid',
                [$vendorId]
            );
        } finally {
            $this->roles->reset();
        }
        $active = $rows[0]['active'] ?? null;

        return $active === true || $active === 't' || $active === '1' || $active === 1;
    }

    private function mint(TenantRecord $tenant, string $email, string $origin): ?string
    {
        $this->asEntry($tenant);
        try {
            if (!$this->allow($email)) {
                return null;
            }
            $rows = $this->statements->bound(
                "SELECT vendor.id
                 FROM vendors AS vendor
                 JOIN persons AS person ON person.party_id = vendor.party_id
                 WHERE lower(person.email) = ? AND vendor.active IS DISTINCT FROM false
                 LIMIT 1",
                [$email]
            );
            $vendorId = is_string($rows[0]['id'] ?? null) ? $rows[0]['id'] : '';
            if ($vendorId === '' || preg_match('#\Ahttps?://[A-Za-z0-9.-]+(?::[0-9]{1,5})?\z#', $origin) !== 1) {
                return null;
            }
            $token = bin2hex(random_bytes(32));
            $this->statements->change(
                "INSERT INTO vendor_entry_tokens (vendor_id, token_hash, expires_at)
                 VALUES (?::uuid, ?, clock_timestamp() + interval '30 minutes')",
                [$vendorId, hash('sha256', $token)]
            );

            return $token;
        } finally {
            $this->roles->reset();
        }
    }

    private function allow(string $email): bool
    {
        $address = $this->request->address;
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT pg_advisory_xact_lock(87201, hashtext(?)), pg_advisory_xact_lock(87202, hashtext(?))');
            $lock->execute([$email, $address]);
            $this->statements->change(
                "DELETE FROM vendor_entry_requests
                 WHERE requested_at <= clock_timestamp() - interval '15 minutes'",
                []
            );
            $emailRows = $this->statements->bound(
                "SELECT count(*) AS requests
                 FROM vendor_entry_requests
                 WHERE email = ?
                   AND requested_at > clock_timestamp() - interval '15 minutes'",
                [$email]
            );
            if (RowValue::integer($emailRows[0]['requests'] ?? 0) >= 5) {
                $this->pdo->commit();

                return false;
            }
            $clientRows = $this->statements->bound(
                "SELECT count(*) AS requests
                 FROM vendor_entry_requests
                 WHERE client_address = ?
                   AND requested_at > clock_timestamp() - interval '15 minutes'",
                [$address]
            );
            if (RowValue::integer($clientRows[0]['requests'] ?? 0) >= 40) {
                $this->pdo->commit();

                return false;
            }
            $this->statements->change(
                'INSERT INTO vendor_entry_requests (email, client_address) VALUES (?, ?)',
                [$email, $address]
            );
            $this->pdo->commit();

            return true;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function asEntry(TenantRecord $tenant): void
    {
        $this->prepare($tenant);
        $this->roles->assume('stall_vendor_entry');
    }

    private function asPoster(TenantRecord $tenant): void
    {
        $this->prepare($tenant);
        $this->roles->assume($tenant->role);
    }

    private function prepare(TenantRecord $tenant): void
    {
        if (preg_match('/^tenant_[0-9a-f]{32}$/', $tenant->schema) !== 1) {
            throw new \RuntimeException('the mall schema is not usable');
        }
        $this->roles->reset();
        if ($this->pdo->exec('SET search_path TO ' . $tenant->schema . ', pg_catalog, extensions') === false) {
            throw new \RuntimeException('the mall schema could not be used');
        }
    }
}
