<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Vendor;

use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Tenant\TenantRecord;
use PDO;

final class VendorPortal
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly DatabaseRole $roles,
        private readonly Statements $statements,
    ) {
    }

    /**
     * @return array{
     *     items: list<array<string, mixed>>,
     *     sales: list<array<string, mixed>>,
     *     payable: int,
     *     expiring: list<array<string, mixed>>,
     *     agreement: array<string, mixed>
     * }
     */
    public function page(TenantRecord $tenant, string $vendorId): array
    {
        $this->assume($tenant);
        $page = [
            'items' => [],
            'sales' => [],
            'payable' => 0,
            'expiring' => [],
            'agreement' => ['agreement_starts_on' => null, 'agreement_ends_on' => null, 'payout_mode' => 'pay'],
        ];
        $this->statements->transaction(function () use ($vendorId, &$page): void {
            $this->useVendor($vendorId);
            $page = [
                'items' => $this->statements->rows('SELECT id, code, description, price_minor, quantity FROM stall_vendor_items ORDER BY code'),
                'sales' => $this->statements->rows('SELECT journal_id, price_minor, quantity, commission_minor, posting_date FROM stall_vendor_sales ORDER BY posting_date'),
                'payable' => $this->payable(),
                'expiring' => $this->statements->rows('SELECT id, code, description, expires_on FROM stall_vendor_expiring ORDER BY expires_on'),
                'agreement' => $this->agreement(),
            ];
        });

        return $page;
    }

    public function prefer(TenantRecord $tenant, string $vendorId, string $mode): void
    {
        $this->assume($tenant);
        $chosen = $mode === 'hold' ? 'hold' : 'pay';
        $this->statements->transaction(function () use ($vendorId, $chosen): void {
            $this->useVendor($vendorId);
            $this->statements->change('SELECT stall_vendor_prefer(?)', [$chosen]);
        });
    }

    private function useVendor(string $vendorId): void
    {
        $this->statements->change("SELECT set_config('stall.vendor_id', ?, true)", [$vendorId]);
    }

    private function payable(): int
    {
        $rows = $this->statements->rows('SELECT open_minor FROM stall_vendor_payable');
        $open = $rows[0]['open_minor'] ?? 0;

        return is_numeric($open) ? (int) $open : 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function agreement(): array
    {
        $rows = $this->statements->rows(
            'SELECT agreement_starts_on, agreement_ends_on, payout_mode FROM stall_vendor_agreement'
        );

        return $rows[0] ?? ['agreement_starts_on' => null, 'agreement_ends_on' => null, 'payout_mode' => 'pay'];
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
