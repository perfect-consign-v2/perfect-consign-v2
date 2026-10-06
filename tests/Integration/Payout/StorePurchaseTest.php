<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Payout;

use PDO;
use PerfectConsign\Feature\Payout\PayoutRunStore;
use PerfectConsign\Feature\Payout\StorePurchase;
use PerfectConsign\Ledger\LedgerPeriods;
use PerfectConsign\Ledger\JournalCash;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Tests\Support\ProbeMall;
use PHPUnit\Framework\TestCase;

final class StorePurchaseTest extends TestCase
{
    public function testAStorePurchaseLandsInThePackResidual(): void
    {
        $root = dirname(__DIR__, 3);
        ProbeMall::ensure($root);
        $pdo = $this->connection();
        $pdo->exec('SET search_path TO ' . $this->schema($pdo) . ', pg_catalog, extensions');
        $statements = new Statements($pdo);
        $ledger = new LedgerStore($pdo, $statements, new LedgerPeriods($pdo), new JournalCash($pdo));
        $runs = new PayoutRunStore($statements);
        $purchases = new StorePurchase($ledger);
        $date = '2096-03-15';
        $pdo->beginTransaction();
        try {
            $vendorId = $ledger->newId();
            $statements->change(
                'INSERT INTO vendors (id, code, first_name, last_name) VALUES (?::uuid, ?, ?, ?)',
                [$vendorId, 'buy' . substr($vendorId, 0, 6), 'Cara', 'Lot']
            );
            $purchase = $purchases->record($vendorId, $date, 2500, 'lot-' . substr($vendorId, 0, 8));
            $runId = $runs->ensureDraft($date, $date, '2096-03-01', $date);
            $row = null;
            foreach ($runs->pack($runId) as $line) {
                if ($line['vendor_id'] === $vendorId) {
                    $row = $line;
                }
            }
            self::assertNotNull($row);
            self::assertSame(2500, $row['adjustment']);
            self::assertSame(2500, $row['payable']);
            self::assertNotSame('', $purchase);
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
