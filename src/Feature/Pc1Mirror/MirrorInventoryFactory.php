<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pc1Mirror;

use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Feature\Company\CompanyLines;
use PerfectConsign\Feature\Company\CompanyStore;
use PerfectConsign\Feature\Inventory\InventoryStore;
use PerfectConsign\Feature\Mall\MallDay;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Shared\Database\CatalogLinks;

final class MirrorInventoryFactory
{
    public function store(MirrorMall $mall): InventoryStore
    {
        return new InventoryStore(
            $mall->statements,
            new CatalogLinks($mall->statements),
            new MallSettings(
                $mall->statements,
                new CompanyStore($mall->statements, new DatabaseRole($mall->pdo), $mall->pdo, new CompanyLines()),
                new MallDay($mall->statements),
            ),
        );
    }
}
