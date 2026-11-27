<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Rent;

use PDO;
use PerfectConsign\Feature\Rent\LateFee;
use PerfectConsign\Feature\Rent\LateFeeDue;
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
    public function testAWaiveNeedsALateFeeAndADate(): void
    {
        $pdo = $this->createStub(PDO::class);
        $statements = new Statements($pdo);
        $fee = new LateFee(new LedgerStore($pdo, $statements, new LedgerPeriods($pdo), new JournalCash($pdo)));

        try {
            $fee->waive('00000000-0000-7000-8000-000000000099', 'October', 'Rain check');
            self::fail('a waive without a date was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('Enter the date of the late fee.', $error->getMessage());
        }

        $this->expectException(DatabaseRefusal::class);
        $this->expectExceptionMessage('Choose a late fee.');
        $fee->waive('not-a-fee', '2026-10-01', 'Rain check');
    }

    public function testTheDatabasePostsTheFeesAndAWaiveKeepsItsNote(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = $this->connection();
        $pdo->exec('SET search_path TO ' . $this->schema($pdo) . ', pg_catalog, extensions');
        $statements = new Statements($pdo);
        $ledger = new LedgerStore($pdo, $statements, new LedgerPeriods($pdo), new JournalCash($pdo));
        $fee = new LateFee($ledger);
        $due = new LateFeeDue($statements, $ledger, $fee);
        $pdo->beginTransaction();
        try {
            $vendorId = $ledger->newId();
            $boothId = $ledger->newId();
            $stayId = $ledger->newId();
            $tag = substr(str_replace('-', '', $boothId), 0, 12);
            $statements->bound('SELECT stall_seed_vendor(?::uuid, ?, ?, ?)', [$vendorId, 'w' . $tag, 'Wanda', 'Waive']);
            $statements->change('INSERT INTO booths (id, code, name) VALUES (?::uuid, ?, ?)', [$boothId, 'W' . $tag, 'Waive booth']);
            $statements->change(
                'INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on) VALUES (?::uuid, ?::uuid, ?::uuid, ?::date)',
                [$stayId, $boothId, $vendorId, '2026-10-01'],
            );
            $statements->change('INSERT INTO assignment_rent (assignment_id, amount_minor) VALUES (?::uuid, ?)', [$stayId, 6000]);
            $statements->change(
                "UPDATE mall SET late_fees_enabled = true, late_fee_grace_days = 5, late_fee_first_minor = 2500,
                        late_fee_next_minor = 1000, late_fee_effective_on = '2026-10-01'",
                []
            );
            $ledger->postThrough('2026-10-01', 'stall_post_rent_charge(?::uuid, ?::date, ?::date)', [$stayId, '2026-10-01', '2026-10-01']);

            self::assertSame(2, $due->apply('2026-10-11'));
            self::assertSame(0, $due->apply('2026-10-11'));
            $fees = $statements->bound(
                "SELECT id::text AS id FROM journals WHERE source_type = 'late_fee' AND posting_key LIKE ? ORDER BY posting_date",
                ['late-fee:' . $stayId . ':%'],
            );
            $first = RowValue::string($fees[0]['id'] ?? null);
            $second = RowValue::string($fees[1]['id'] ?? null);

            $pdo->exec('SAVEPOINT long_note');
            try {
                $fee->waive($first, '2026-10-12', str_repeat('n', 501));
                self::fail('a waive note past 500 characters was accepted');
            } catch (DatabaseRefusal $error) {
                self::assertSame('Enter a shorter waive note.', $error->getMessage());
            }
            $pdo->exec('ROLLBACK TO SAVEPOINT long_note');
            $reversal = $fee->waive($first, '2026-10-12', 'Rain check');
            $noted = $statements->bound('SELECT description FROM journals WHERE id = ?::uuid', [$reversal]);
            self::assertSame('Late fee waived. Rain check', RowValue::string($noted[0]['description'] ?? null));
            $again = $fee->waive($second, '2026-10-12', '   ');
            $blank = $statements->bound('SELECT description FROM journals WHERE id = ?::uuid', [$again]);
            self::assertSame('Late fee waived', RowValue::string($blank[0]['description'] ?? null));
            self::assertSame(0, $due->apply('2026-10-12'));
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
