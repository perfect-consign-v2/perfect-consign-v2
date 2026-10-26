<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Vendor;

use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Mail\OutboundMail;
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
        private readonly OutboundMail $mail,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function request(TenantRecord $tenant, string $email, string $origin): void
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return;
        }
        $this->assume($tenant);
        if (!$this->allow($email)) {
            return;
        }
        $rows = $this->statements->bound(
            'SELECT id FROM vendors WHERE lower(email) = ? AND active IS DISTINCT FROM false LIMIT 1',
            [$email]
        );
        $vendorId = is_string($rows[0]['id'] ?? null) ? $rows[0]['id'] : '';
        if ($vendorId === '' || preg_match('#\Ahttps?://[A-Za-z0-9.-]+(?::[0-9]{1,5})?\z#', $origin) !== 1) {
            return;
        }
        $token = bin2hex(random_bytes(32));
        $this->statements->change(
            "INSERT INTO vendor_entry_tokens (vendor_id, token_hash, expires_at)
             VALUES (?::uuid, ?, clock_timestamp() + interval '30 minutes')",
            [$vendorId, hash('sha256', $token)]
        );
        try {
            $this->mail->deliver(
                $email,
                'Your consignor link',
                $origin . '/' . $tenant->slug . '/consignor/enter/' . $token
            );
        } catch (\Throwable $error) {
            $this->logger->error('consignor entry mail was refused', [
                'slug' => $tenant->slug,
                'message' => $error->getMessage(),
            ]);
        }
    }

    public function open(TenantRecord $tenant, string $hash): string
    {
        $this->assume($tenant);
        $rows = $this->statements->bound(
            'SELECT stall_vendor_open(?) AS vendor_id',
            [$hash]
        );
        $vendorId = $rows[0]['vendor_id'] ?? null;

        return is_string($vendorId) ? $vendorId : '';
    }

    private function allow(string $email): bool
    {
        $rows = $this->statements->bound(
            "SELECT count(*) AS requests
             FROM vendor_entry_requests
             WHERE email = ?
               AND requested_at > clock_timestamp() - interval '15 minutes'",
            [$email]
        );
        if (RowValue::integer($rows[0]['requests'] ?? 0) >= 5) {
            return false;
        }
        $this->statements->change(
            'INSERT INTO vendor_entry_requests (email) VALUES (?)',
            [$email]
        );

        return true;
    }

    private function assume(TenantRecord $tenant): void
    {
        if (preg_match('/^tenant_[0-9a-f]{32}$/', $tenant->schema) !== 1) {
            throw new \RuntimeException('the mall schema is not usable');
        }
        $this->roles->reset();
        if ($this->pdo->exec('SET search_path TO ' . $tenant->schema . ', pg_catalog, extensions') === false) {
            throw new \RuntimeException('the mall schema could not be used');
        }
        $this->roles->assume($tenant->role);
    }
}
