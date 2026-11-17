<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Vendor;

use PerfectConsign\Feature\Mall\MallGuard;
use PerfectConsign\Feature\Mall\MallSettings;
use PDO;

/** The only reader of the vendor portal and vendor inventory switches. */
final class VendorPortalAccess
{
    public function __construct(private readonly MallSettings $mall)
    {
    }

    public function portalEnabled(): bool
    {
        return $this->flags()['portal'];
    }

    public function inventoryEnabled(): bool
    {
        return $this->flags()['inventory'];
    }

    /**
     * Inventory is on only when the portal is on as well.
     *
     * @return array{portal: bool, inventory: bool}
     */
    public function flags(): array
    {
        $stored = $this->mall->getVendorFlags();

        return [
            'portal' => $stored['portal'],
            'inventory' => $stored['portal'] && $stored['inventory'],
        ];
    }

    public function inventoryNav(bool $roled, string $role, string $preview): bool
    {
        if (!$roled || ($role !== 'vendor' && $preview === '')) {
            return true;
        }

        return $this->inventoryEnabled();
    }

    public function endClosedSession(bool $roled, string $role, callable $close): void
    {
        if (!$roled || $role !== 'vendor' || $this->portalEnabled()) {
            return;
        }
        $close();
    }

    public static function portalEnabledIn(PDO $pdo, string $schema): bool
    {
        if (preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            throw new \RuntimeException('the mall schema is not usable');
        }
        $statement = $pdo->prepare(
            'SELECT vendor_portal_enabled FROM ' . $schema . '.mall'
        );
        $statement->execute();
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new \RuntimeException('the mall has no clock');
        }

        return MallGuard::flag($row['vendor_portal_enabled'] ?? null);
    }
}
