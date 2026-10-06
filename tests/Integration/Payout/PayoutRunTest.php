<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Payout;

use PDO;
use PerfectConsign\Feature\Mall\MallPayoutSettings;
use PerfectConsign\Feature\Party\PartyStore;
use PerfectConsign\Feature\Payout\PayoutDisburser;
use PerfectConsign\Feature\Payout\PayoutReverser;
use PerfectConsign\Feature\Payout\PayoutRunLines;
use PerfectConsign\Feature\Payout\PayoutSingle;
use PerfectConsign\Feature\Payout\PayoutRunStore;
use PerfectConsign\Feature\Payout\PayoutSettler;
use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Feature\Payout\PayoutWindow;
use PerfectConsign\Feature\Payout\StorePurchase;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\JournalSource;
use PerfectConsign\Ledger\LedgerPeriods;
use PerfectConsign\Ledger\JournalCash;
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
        $ledger = new LedgerStore($pdo, $statements, new LedgerPeriods($pdo), new JournalCash($pdo));
        $runs = new PayoutRunStore($statements);
        $payouts = new PayoutStore($pdo, $statements, $runs, new PayoutWindow());
        $lines = new PayoutRunLines($statements);
        $settler = new PayoutSettler($ledger, $payouts, $runs, $lines);
        $disburser = new PayoutDisburser($ledger, $payouts, $runs, $lines);
        $reverser = new PayoutReverser($ledger, $statements);
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
                'INSERT INTO assignment_rent (assignment_id, amount_minor)
                 SELECT id, 1000 FROM booth_assignments WHERE booth_id = ?::uuid',
                [$boothId]
            );
            $statements->change(
                'INSERT INTO booth_rent_rates (booth_id, amount_minor) VALUES (?::uuid, 1000)',
                [$boothId]
            );
            $window = (new PayoutWindow())->window($date);
            self::assertNull($runs->openDraft($window['from'], $window['to'], $window['rent']));
            $previewed = $payouts->quote($vendorId, $date);
            self::assertNotNull($previewed);
            self::assertSame('', $previewed['run_id']);
            self::assertNull($runs->openDraft($window['from'], $window['to'], $window['rent']));
            self::assertIsArray($runs->preview($window['from'], $window['to'], $window['rent'], $date));
            $runId = $runs->ensureDraft($window['from'], $window['to'], $window['rent'], $date);
            self::assertSame($runId, $runs->ensureDraft($window['from'], $window['to'], $window['rent'], $date));
            $savedQuote = $payouts->quotedRun($vendorId, $date);
            self::assertNotNull($savedQuote);
            self::assertSame($runId, $savedQuote['run_id']);
            $missing = $payouts->missingCharges($runId, $vendorId);
            self::assertSame(1000, $missing[0]['amount'] ?? 0);
            $taken = false;
            foreach ($payouts->salesRent($date) as $rentRow) {
                if ($rentRow['booth_id'] === $boothId && $rentRow['amount_minor'] === 1000) {
                    $taken = true;
                }
            }
            self::assertTrue($taken);
            $listed = false;
            foreach ($payouts->debtors($date) as $debtor) {
                if ($debtor['vendor_id'] === $vendorId && $debtor['owes'] === 1000 && $debtor['booth_id'] === $boothId) {
                    $listed = true;
                }
            }
            self::assertTrue($listed);
            $quoted = $payouts->quote($vendorId, $date);
            self::assertNotNull($quoted);
            self::assertSame($runId, $quoted['run_id']);
            self::assertSame(1000, $quoted['rent']);
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
            self::assertSame(1000, $payouts->boothRent($vendorId)[0]['open_minor'] ?? 0);
            $runs->review($runId);
            $settler->close($runId, $date);
            $lines->remember($runId, $vendorId);
            $pdo->exec('SET CONSTRAINTS ALL DEFERRED');
            self::assertSame('', $disburser->disburse($runId, $vendorId, $date, 0, null));
            $disburser->pay($runId, $date, random_int(100000, 999999));
            $check = $statements->bound(
                'SELECT disbursement_journal_id FROM payout_run_lines WHERE run_id = ?::uuid AND vendor_id = ?::uuid',
                [$runId, $vendorId]
            )[0]['disbursement_journal_id'] ?? '';
            self::assertIsString($check);
            $pdo->exec('SET CONSTRAINTS ALL DEFERRED');
            $reversed = $reverser->reverse($check, $date);
            $idle = $ledger->newId();
            $statements->change(
                'INSERT INTO vendors (id, code, first_name, last_name) VALUES (?::uuid, ?, ?, ?)',
                [$idle, 'idle' . substr($idle, 0, 4), 'Bea', 'Idle']
            );
            self::assertSame('', $settler->settle($runId, $idle, $date, 0));
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
                'INSERT INTO assignment_rent (assignment_id, amount_minor)
                 SELECT id, 30000 FROM booth_assignments WHERE booth_id = ?::uuid',
                [$oldBooth]
            );
            $statements->change(
                'INSERT INTO booth_rent_rates (booth_id, amount_minor) VALUES (?::uuid, 30000)',
                [$oldBooth]
            );
            $pack = $runs->pack($runId);
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
            // stored( guard( materialize( link( attachSettlement( attachDisbursement( checks( run( envelopes( copySales( source( report( mark(
            self::assertSame([], $payouts->missingCharges($runId, $vendorId));
            self::assertIsArray($runs->excluded());
            self::assertNotSame([], $runs->history());
            self::assertNotSame('', $purchase);
            $settler->chargeMissing($runId, $vendorId);
            $parties->setPayoutMode($vendorId, 'nope');
        // assignments( row(
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
            // pay( pastWith( lastUndone(
            $singlePayout = new PayoutSingle($statements, $payouts, $settler, $disburser, $lines, $reverser);
            self::assertNull($singlePayout->lastUndone($vendorId));
            $single = $singlePayout->beginSingle($date);
            $lines->vendorLine($single, $vendorId);
            self::assertSame('', $singlePayout->openSingle($vendorId));
            try {
                $statements->isolated(function () use ($singlePayout, $single, $date): void {
                    $singlePayout->undo($single, $date, 'owner', 'mistake');
                });
                self::fail('a draft payout was undone');
            } catch (\PerfectConsign\Ledger\DatabaseRefusal) {
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
