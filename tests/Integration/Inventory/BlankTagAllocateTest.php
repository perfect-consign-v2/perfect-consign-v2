<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Inventory;

use PDO;
use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Feature\Company\CompanyLines;
use PerfectConsign\Feature\Company\CompanyStore;
use PerfectConsign\Feature\Inventory\InventoryStore;
use PerfectConsign\Feature\Inventory\ItemDates;
use PerfectConsign\Feature\Inventory\ItemEntry;
use PerfectConsign\Feature\Inventory\ItemSale;
use PerfectConsign\Feature\Mall\MallDay;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Shared\Database\CatalogLinks;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\SeedVendor;
use PHPUnit\Framework\TestCase;

final class BlankTagAllocateTest extends TestCase
{
    public function testABlankTagAndBarcodeAreFilledOnSave(): void
    {
        $root = dirname(__DIR__, 3);
        ProbeMall::ensure($root);
        $pdo = $this->connection();
        $pdo->exec('SET search_path TO ' . $this->schema($pdo) . ', pg_catalog, extensions');
        $statements = new Statements($pdo);
        $mall = new MallSettings(
            $statements,
            new CompanyStore($statements, new DatabaseRole($pdo), $pdo, new CompanyLines()),
            new MallDay($statements),
        );
        $store = new InventoryStore($statements, new CatalogLinks($statements), $mall);
        $today = '2099-04-02';
        $pdo->beginTransaction();
        try {
            $vendorId = SeedVendor::create($statements, 'tg' . substr(bin2hex(random_bytes(3)), 0, 6), 'Tag', 'Blank');
            $id = $store->add(
                new ItemEntry(
                    '',
                    '',
                    $vendorId,
                    null,
                    [
                        'color' => null,
                        'category' => null,
                        'size' => null,
                        'condition' => null,
                        'department' => null,
                    ],
                    'Blank tag coat',
                    new ItemSale(1200, 1, null, false),
                    new ItemDates($today, null),
                    'consigned',
                ),
                $today,
            );
            $row = $store->find($id);
            self::assertNotNull($row);
            self::assertMatchesRegularExpression('/^TG20990402[0-9]{4}$/', (string) ($row['code'] ?? ''));
            self::assertMatchesRegularExpression('/^BC20990402[0-9]{4}$/', (string) ($row['barcode'] ?? ''));
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    private function schema(PDO $pdo): string
    {
        $listed = $pdo->query("SELECT schema_name FROM platform.tenants WHERE slug = 'webprobe'");
        if ($listed === false) {
            self::fail('webprobe schema is missing');
        }
        $schema = $listed->fetchColumn();
        if (!is_string($schema) || preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            self::fail('webprobe schema is missing');
        }

        return $schema;
    }

    private function connection(): PDO
    {
        return new PDO(
            'pgsql:host=' . $this->env('STALL_DB_HOST') . ';port=' . $this->env('STALL_DB_PORT') . ';dbname=' . $this->env('STALL_DB_NAME'),
            $this->env('STALL_DB_USER'),
            $this->env('STALL_DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    private function env(string $name): string
    {
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            self::fail($name . ' is required');
        }

        return $value;
    }
}
