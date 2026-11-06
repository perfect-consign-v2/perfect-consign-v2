<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pc1Mirror;

use PDO;
use PerfectConsign\Schema\TenantProvisioner;

final class MirrorMallFactory
{
    public static function provision(PDO $pdo, string $sqlDirectory): MirrorMall
    {
        $schema = (new TenantProvisioner($pdo, $sqlDirectory))->provision(
            MirrorConstants::TARGET_SLUG,
            MirrorConstants::TARGET_DISPLAY
        );

        return MirrorMallServices::open($pdo, $schema);
    }

    public static function openExisting(PDO $pdo): MirrorMall
    {
        $statement = $pdo->prepare('SELECT schema_name FROM platform.tenants WHERE slug = ?');
        $statement->execute([MirrorConstants::TARGET_SLUG]);
        $schema = $statement->fetchColumn();
        if (!is_string($schema) || preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            throw new MirrorException('beccas-mirror tenant is missing');
        }

        return MirrorMallServices::open($pdo, $schema);
    }
}
