<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pc1Mirror;

use PDO;

final class MirrorWiper
{
    public function wipe(PDO $pdo, string $slug): void
    {
        if ($slug !== MirrorConstants::TARGET_SLUG) {
            throw new MirrorException('mirror wipe is limited to ' . MirrorConstants::TARGET_SLUG);
        }
        require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'wipe-tenant.php';
        \wipeTenant($pdo, $slug, $slug);
    }
}
