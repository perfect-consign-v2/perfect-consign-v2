<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Rent;

use PDO;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Feature\Rent\RentLines;
use PerfectConsign\Feature\Rent\RentStore;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\JournalSource;
use PerfectConsign\Ledger\LedgerPeriods;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\PostedLines;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Tests\Support\ProbeMall;
use PHPUnit\Framework\TestCase;

final class RentChargeTest extends TestCase
{
    public function testAMoveInAndAnOverridePostTheAmountTheLedgerAccepts(): void
    {
        $root = dirname(__DIR__, 3);
        ProbeMall::ensure($root);
        $pdo = $this->connection();
        $pdo->exec('SET search_path TO ' . $this->schema($pdo) . ', pg_catalog, extensions');
        $statements = new Statements($pdo);
        $ledger = new LedgerStore($pdo, $statements, new LedgerPeriods($pdo));
        $rent = new RentStore($statements, new MallSettings($statements), new RentLines());
        $pdo->beginTransaction();
        try {
            $vendorId = $ledger->newId();
            $tag = substr(str_replace('-', '', $vendorId), 0, 6);
            $statements->change(
                'INSERT INTO vendors (id, code, first_name, last_name) VALUES (?::uuid, ?, ?, ?)',
                [$vendorId, 'r' . $tag, 'Ada', 'Lane']
            );
            $moveIn = $this->booth($statements, $ledger, 'm' . $tag, '2026-06-01', 9000);
            $this->assign($statements, $moveIn, $vendorId, '2026-06-15', null);
            $prorated = $rent->chargeAmount($moveIn, '2026-06-15');
            self::assertSame(4800, $prorated);
            self::assertTrue($this->listed($rent->uncharged('2026-10-04', '2026-10'), $moveIn, '2026-06', 4800));
            $this->post($ledger, $moveIn, '2026-06-15', $prorated);
            self::assertFalse($this->listed($rent->uncharged('2026-10-04', '2026-10'), $moveIn, '2026-06', 4800));

            $overridden = $this->booth($statements, $ledger, 'o' . $tag, '2026-03-01', 3100);
            $this->assign($statements, $overridden, $vendorId, '2026-03-15', 6200);
            $override = $rent->chargeAmount($overridden, '2026-03-15');
            self::assertSame(3400, $override);
            $this->post($ledger, $overridden, '2026-03-15', $override);

            $vacant = $this->booth($statements, $ledger, 'v' . $tag, '2026-06-01', 7700);
            try {
                $rent->chargeAmount($vacant, '2026-06-01');
                self::fail('covering( a vacant booth was charged');
            } catch (DatabaseRefusal $refusal) {
                self::assertSame('a rent charge requires an assignment that covers the charge date', $refusal->getMessage());
            }
        } finally {
            $pdo->rollBack();
        }
    }

    /**
     * @param list<array{booth_id: string, code: string, vendor_name: ?string, month: string, label: string, amount_minor: int, charge_date: string}> $rows
     */
    private function listed(array $rows, string $boothId, string $month, int $amount): bool
    {
        foreach ($rows as $row) {
            if ($row['booth_id'] === $boothId && $row['month'] === $month && $row['amount_minor'] === $amount) {
                return true;
            }
        }

        return false;
    }

    private function booth(Statements $statements, LedgerStore $ledger, string $code, string $month, int $rate): string
    {
        $id = $ledger->newId();
        $statements->change(
            'INSERT INTO booths (id, code, name) VALUES (?::uuid, ?, ?)',
            [$id, $code, 'Booth ' . $code]
        );
        $statements->change(
            'INSERT INTO booth_rent_rates (booth_id, month_start, amount_minor) VALUES (?::uuid, ?::date, ?)',
            [$id, $month, $rate]
        );

        return $id;
    }

    private function assign(Statements $statements, string $boothId, string $vendorId, string $startsOn, ?int $override): void
    {
        $rows = $statements->bound(
            'INSERT INTO booth_assignments (booth_id, vendor_id, starts_on) VALUES (?::uuid, ?::uuid, ?::date) RETURNING id',
            [$boothId, $vendorId, $startsOn]
        );
        $id = $rows[0]['id'] ?? null;
        self::assertIsString($id);
        if ($override === null) {
            return;
        }
        $statements->change(
            'INSERT INTO assignment_rent (assignment_id, amount_minor) VALUES (?::uuid, ?)',
            [$id, $override]
        );
    }

    private function post(LedgerStore $ledger, string $boothId, string $date, int $amount): void
    {
        $month = substr($date, 0, 7);
        $ledger->post(new JournalDraft(
            postingKey: 'rent-charge:' . $boothId . ':' . $month,
            postingDate: $date,
            source: new JournalSource('rent_charge', $boothId . ':' . $month, 'Rent charge'),
            lines: [
                PostedLines::entry($ledger->roleAccount('booth_rent_receivable'), $amount, 0, 'booth', $boothId),
                PostedLines::entry($ledger->roleAccount('rent_revenue'), 0, $amount, null, null),
            ],
        ));
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
