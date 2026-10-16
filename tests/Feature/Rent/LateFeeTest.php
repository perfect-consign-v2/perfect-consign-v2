<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Rent;

use PDO;
use PerfectConsign\Feature\Rent\LateFee;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalCash;
use PerfectConsign\Ledger\LedgerPeriods;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;
use PerfectConsign\Tests\Support\ProbeMall;
use PHPUnit\Framework\TestCase;

final class LateFeeTest extends TestCase
{
    public function testALateFeeNeedsABoothAndADate(): void
    {
        $pdo = $this->createStub(PDO::class);
        $statements = new Statements($pdo);
        $fee = new LateFee(new LedgerStore($pdo, $statements, new LedgerPeriods($pdo), new JournalCash($pdo)), $statements);

        try {
            $fee->post('booth', '2026-10', '2026-10-01', 150);
            self::fail('a late fee without a booth was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('Choose a booth.', $error->getMessage());
        }

        try {
            $fee->post('00000000-0000-7000-8000-000000000004', '2026-10', 'October', 150);
            self::fail('a late fee without a date was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('Enter the date of the late fee.', $error->getMessage());
        }

        try {
            $fee->waive('00000000-0000-7000-8000-000000000099', '2026-10-01', str_repeat('n', 501));
            self::fail('a waive note past 500 characters was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('Enter a shorter waive note.', $error->getMessage());
        }

        $this->expectException(DatabaseRefusal::class);
        $this->expectExceptionMessage('Choose a late fee.');
        $fee->waive('not-a-fee', '2026-10-01', 'Rain check');
        // note( requireFee(
    }

    public function testANotedWaiveIsStoredOnTheReversal(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = $this->connection();
        $pdo->exec('SET search_path TO ' . $this->schema($pdo) . ', pg_catalog, extensions');
        $statements = new Statements($pdo);
        $ledger = new LedgerStore($pdo, $statements, new LedgerPeriods($pdo), new JournalCash($pdo));
        $fee = new LateFee($ledger, $statements);
        $pdo->beginTransaction();
        try {
            $boothId = $ledger->newId();
            $statements->change(
                'INSERT INTO booths (id, code, name) VALUES (?::uuid, ?, ?)',
                [$boothId, 'W' . substr(str_replace('-', '', $boothId), 0, 12), 'Waive booth'],
            );
            $posted = $fee->post($boothId, '2026-10', '2026-10-01', 2500);
            $reversal = $fee->waive($posted, '2026-10-02', 'Rain check');
            $noted = $statements->bound('SELECT description FROM journals WHERE id = ?::uuid', [$reversal]);
            self::assertSame('Late fee waived. Rain check', RowValue::string($noted[0]['description'] ?? null));
            $plain = $fee->post($boothId, '2026-11', '2026-11-01', 1000);
            $again = $fee->waive($plain, '2026-11-01', '   ');
            $blank = $statements->bound('SELECT description FROM journals WHERE id = ?::uuid', [$again]);
            self::assertSame('Late fee waived', RowValue::string($blank[0]['description'] ?? null));
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
