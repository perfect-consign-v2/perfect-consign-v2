<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Book;

use PerfectConsign\Bootstrap\ActiveRegister;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Feature\Book\JournalController;
use PerfectConsign\Feature\Dev\LocalAdmin;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Feature\Register\RegisterStore;
use PerfectConsign\Ledger\JournalCash;
use PerfectConsign\Ledger\LedgerPeriods;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Tests\Support\ProbeMall;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class JournalVoidDisbursementTest extends TestCase
{
    public function testDisbursementAndSettlementCannotVoid(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = LocalAdmin::open()->pdo();
        $schema = $this->schema($pdo);
        $pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');
        $statements = new Statements($pdo);
        $ledger = new LedgerStore($pdo, $statements, new LedgerPeriods($pdo), new JournalCash($pdo));
        $controller = (new \ReflectionClass(JournalController::class))->newInstanceWithoutConstructor();
        $canVoid = new ReflectionMethod(JournalController::class, 'canVoid');
        foreach (['check_disbursement', 'cash_disbursement', 'safe_disbursement', 'settlement', 'period_close'] as $source) {
            self::assertFalse(
                $canVoid->invoke($controller, ['source_type' => $source, 'is_reversal' => false], false),
                $source . ' must refuse void'
            );
        }
        self::assertTrue(
            $canVoid->invoke($controller, ['source_type' => 'sale', 'is_reversal' => false], false)
        );
        self::assertFalse(
            $canVoid->invoke($controller, ['source_type' => 'sale', 'is_reversal' => false], true)
        );
    }

    private function schema(\PDO $pdo): string
    {
        $statement = $pdo->prepare('SELECT schema_name FROM platform.tenants WHERE slug = ?');
        $statement->execute([ProbeMall::SLUG]);
        $schema = $statement->fetchColumn();
        if (!is_string($schema) || preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            self::fail('probe mall schema is missing');
        }

        return $schema;
    }
}
