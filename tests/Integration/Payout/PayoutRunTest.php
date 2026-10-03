<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Payout;

use PDO;
use PerfectConsign\Feature\Mall\MallPayoutSettings;
use PerfectConsign\Feature\Party\PartyStore;
use PerfectConsign\Feature\Payout\PayoutDisburser;
use PerfectConsign\Feature\Payout\PayoutReverser;
use PerfectConsign\Feature\Payout\PayoutRunStore;
use PerfectConsign\Feature\Payout\PayoutSettler;
use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Feature\Payout\StorePurchase;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\JournalSource;
use PerfectConsign\Ledger\LedgerPeriods;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\PostedLines;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Tests\Support\ProbeMall;
use PHPUnit\Framework\TestCase;

final class PayoutRunTest extends TestCase
{
    public function testSettleDisburseAndReverseAStorePurchase(): void
    {
        $root = dirname(__DIR__, 3);
        ProbeMall::ensure($root);
        $pdo = $this->connection();
        $pdo->exec('SET search_path TO ' . $this->schema($pdo) . ', pg_catalog, extensions');
        $statements = new Statements($pdo);
        $ledger = new LedgerStore($pdo, $statements, new LedgerPeriods($pdo));
        $payouts = new PayoutStore($pdo, $statements);
        $runs = new PayoutRunStore($statements, $payouts);
        $settler = new PayoutSettler($ledger, $payouts);
        $disburser = new PayoutDisburser($ledger);
        $reverser = new PayoutReverser($ledger);
        $purchases = new StorePurchase($ledger);
        $mall = new MallPayoutSettings($statements);
        $parties = new PartyStore($statements);
        $date = $this->openDate($statements);
        $pdo->beginTransaction();
        try {
            $vendorId = $ledger->newId();
            $statements->change(
                'INSERT INTO vendors (id, code, first_name, last_name) VALUES (?::uuid, ?, ?, ?)',
                [$vendorId, 'pay' . substr($vendorId, 0, 6), 'Ada', 'Booth']
            );
            $boothId = $ledger->newId();
            $statements->change(
                'INSERT INTO booths (id, code, name) VALUES (?::uuid, ?, ?)',
                [$boothId, 'b' . substr($boothId, 0, 6), 'Booth']
            );
            $statements->change(
                'INSERT INTO booth_assignments (booth_id, vendor_id, starts_on) VALUES (?::uuid, ?::uuid, ?::date)',
                [$boothId, $vendorId, $date]
            );
            $statements->change(
                'INSERT INTO booth_rent_rates (booth_id, month_start, amount_minor) VALUES (?::uuid, ?::date, 1000)',
                [$boothId, substr($date, 0, 7) . '-01']
            );
            $missing = $payouts->missingRent($vendorId, $date);
            self::assertSame(1000, $missing[0]['amount'] ?? 0);
            $purchase = $purchases->record($vendorId, $date, 2500, 'buy-' . substr($vendorId, 0, 8));
            $pdo->exec('SET CONSTRAINTS ALL DEFERRED');
            $ledger->post(new JournalDraft(
                postingKey: 'rent-charge:' . $boothId . ':' . substr($date, 0, 7),
                postingDate: $date,
                source: new JournalSource('rent_charge', $boothId . ':' . substr($date, 0, 7), 'Rent charge'),
                lines: [
                    PostedLines::entry($ledger->roleAccount('booth_rent_receivable'), 1000, 0, 'booth', $boothId),
                    PostedLines::entry($ledger->roleAccount('rent_revenue'), 0, 1000, null, null),
                ],
            ));
            $pdo->exec('SET CONSTRAINTS ALL DEFERRED');
            $rent = substr($date, 0, 7) . '-01';
            $to = (new \DateTimeImmutable($rent))->modify('-1 day')->format('Y-m-d');
            $runId = $runs->begin(substr($to, 0, 7) . '-01', $to, $rent, $date);
            $settled = $settler->settle($runId, $vendorId, $date);
            self::assertNotSame('', $settled);
            $pdo->exec('SET CONSTRAINTS ALL DEFERRED');
            self::assertSame('', $disburser->disburse($runId, $vendorId, $date, 0, null));
            $check = $disburser->disburse($runId, $vendorId, $date, 1500, (string) random_int(100000, 999999));
            $pdo->exec('SET CONSTRAINTS ALL DEFERRED');
            $runs->remember($runId, $vendorId, 1500, 0, $settled);
            $runs->markSettled($runId);
            $runs->markPaid($runId);
            $reversed = $reverser->reverse($check, $date);
            $idle = $ledger->newId();
            $statements->change(
                'INSERT INTO vendors (id, code, first_name, last_name) VALUES (?::uuid, ?, ?, ?)',
                [$idle, 'idle' . substr($idle, 0, 4), 'Bea', 'Idle']
            );
            self::assertSame('', $settler->settle($runId, $idle, $date));
            $backdated = $ledger->newId();
            $statements->change(
                'INSERT INTO vendors (id, code, first_name, last_name) VALUES (?::uuid, ?, ?, ?)',
                [$backdated, 'old' . substr($backdated, 0, 4), 'Nell', 'Owes']
            );
            $oldBooth = $ledger->newId();
            $oldStart = (new \DateTimeImmutable(substr($date, 0, 7) . '-01'))->modify('-2 months')->format('Y-m-d');
            $statements->change(
                'INSERT INTO booths (id, code, name) VALUES (?::uuid, ?, ?)',
                [$oldBooth, 'z' . substr($oldBooth, 0, 6), 'Old']
            );
            $statements->change(
                'INSERT INTO booth_assignments (booth_id, vendor_id, starts_on) VALUES (?::uuid, ?::uuid, ?::date)',
                [$oldBooth, $backdated, $oldStart]
            );
            $statements->change(
                'INSERT INTO booth_rent_rates (booth_id, month_start, amount_minor) VALUES (?::uuid, ?::date, 30000)',
                [$oldBooth, $oldStart]
            );
            $pack = $runs->pack(substr($date, 0, 7) . '-01', $date);
            $idleRow = null;
            $owedRow = null;
            foreach ($pack as $line) {
                if ($line['vendor_id'] === $idle) {
                    $idleRow = $line;
                }
                if ($line['vendor_id'] === $backdated) {
                    $owedRow = $line;
                }
            }
            self::assertNotNull($idleRow);
            self::assertSame('', $idleRow['booth']);
            self::assertNotNull($owedRow);
            self::assertSame(90000, $owedRow['owes']);
            self::assertSame(0, $owedRow['check']);
            self::assertSame('', $disburser->disburse($runId, $backdated, $date, $owedRow['check'], null));
            self::assertNotSame('', $reversed);
            self::assertNotSame([], $pack);
            self::assertIsArray($payouts->missingByVendor($date));
            self::assertIsArray($runs->excluded());
            self::assertNotSame([], $runs->history());
            self::assertNotSame('', $purchase);
            $settler->chargeMissing($vendorId, $date);
            $parties->setPayoutMode($vendorId, 'nope');
        // rate( assignments( months( monthCharge( row(
            $mall->save('nope', 'nope', true);
            self::assertSame('both', $mall->operatingMode());
            self::assertSame('monthly', $mall->payoutCadence());
            self::assertTrue($mall->rentPrepaid());
            try {
                $purchases->record($vendorId, $date, 0, 'zero');
                self::fail('a zero store purchase was accepted');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
            try {
                $purchases->record($vendorId, $date, 100, ' ');
                self::fail('a blank store purchase was accepted');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
            try {
                $reverser->reverse($ledger->newId(), $date);
                self::fail('a missing reversal was accepted');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
            try {
                $parties->setPayoutMode($ledger->newId(), 'pay');
                self::fail('a missing vendor mode was accepted');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    private function openDate(Statements $statements): string
    {
        $rows = $statements->rows(
            "SELECT to_char(starts_on, 'YYYY-MM-DD') AS starts_on
             FROM accounting_periods WHERE status = 'open' ORDER BY starts_on LIMIT 1"
        );
        if ($rows !== [] && is_string($rows[0]['starts_on'] ?? null)) {
            return $rows[0]['starts_on'];
        }

        return '2096-03-01';
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
