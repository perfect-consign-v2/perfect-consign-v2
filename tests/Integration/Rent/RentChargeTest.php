<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Rent;

use PDO;
use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Feature\Company\CompanyLines;
use PerfectConsign\Feature\Company\CompanyStore;
use PerfectConsign\Feature\Mall\MallPayoutSettings;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Feature\Payout\OccupiedPack;
use PerfectConsign\Feature\Payout\PayoutRunLines;
use PerfectConsign\Feature\Payout\PayoutRunStore;
use PerfectConsign\Feature\Payout\PayoutSettler;
use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Feature\Payout\PayoutWindow;
use PerfectConsign\Feature\Payout\VendorClawback;
use PerfectConsign\Feature\Rent\LateFee;
use PerfectConsign\Feature\Rent\RentLines;
use PerfectConsign\Feature\Rent\RentMoves;
use PerfectConsign\Feature\Rent\RentPayment;
use PerfectConsign\Feature\Rent\RentPosting;
use PerfectConsign\Feature\Rent\RentReceipt;
use PerfectConsign\Feature\Rent\RentSlip;
use PerfectConsign\Feature\Rent\RentStore;
use PerfectConsign\Shared\Scalar\RowValue;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalCash;
use PerfectConsign\Ledger\LedgerPeriods;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Database\CatalogLinks;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\QuietAudit;
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
        $ledger = new LedgerStore($pdo, $statements, new LedgerPeriods($pdo), new JournalCash($pdo));
        $rent = new RentStore($statements, $this->mall($pdo, $statements), new RentLines(), $ledger);
        $pdo->beginTransaction();
        try {
            self::assertSame('', (new JournalCash($pdo))->tendered($ledger->newId()));
            self::assertIsArray((new CatalogLinks($statements))->referenced('items'));
            $vendorId = $ledger->newId();
            $tag = substr(str_replace('-', '', $vendorId), 0, 6);
            $statements->change(
                'INSERT INTO vendors (id, code, first_name, last_name) VALUES (?::uuid, ?, ?, ?)',
                [$vendorId, 'r' . $tag, 'Ada', 'Lane']
            );
            $moveIn = $this->booth($statements, $ledger, 'm' . $tag, 9000);
            $this->assign($statements, $moveIn, $vendorId, '2026-06-15', null);
            $prorated = $rent->chargeAmount($moveIn, '2026-06-15');
            self::assertSame(4800, $prorated);
            self::assertTrue($this->listed($rent->uncharged('2026-10-04', '2026-10'), $moveIn, '2026-06', 4800));
            $rent->postCharge($moveIn, '2026-06-15');
            self::assertFalse($this->listed($rent->uncharged('2026-10-04', '2026-10'), $moveIn, '2026-06', 4800));

            $overridden = $this->booth($statements, $ledger, 'o' . $tag, 3100);
            $this->assign($statements, $overridden, $vendorId, '2026-03-15', 6200);
            $override = $rent->chargeAmount($overridden, '2026-03-15');
            self::assertSame(3400, $override);
            $rent->postCharge($overridden, '2026-03-15');

            $vacant = $this->booth($statements, $ledger, 'v' . $tag, 7700);
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

    public function testARentReceiptDebitsTheTenderOnTheOpenDrawer(): void
    {
        $root = dirname(__DIR__, 3);
        ProbeMall::ensure($root);
        $pdo = $this->connection();
        $pdo->exec('SET search_path TO ' . $this->schema($pdo) . ', pg_catalog, extensions');
        $statements = new Statements($pdo);
        $ledger = new LedgerStore($pdo, $statements, new LedgerPeriods($pdo), new JournalCash($pdo));
        $runs = new PayoutRunStore($statements, new OccupiedPack($statements));
        $payouts = new PayoutStore($pdo, $statements, $runs, new PayoutWindow());
        $receipts = new RentReceipt(
            $pdo,
            $statements,
            $payouts,
            new MallPayoutSettings($statements),
            new RentPosting(
                $ledger,
                $payouts,
                new PayoutSettler(
                    $ledger,
                    $payouts,
                    $runs,
                    new PayoutRunLines($statements),
                    QuietAudit::make($statements),
                    new VendorClawback($ledger, $statements),
                ),
                new MallPayoutSettings($statements),
                new RentMoves($statements, new MallPayoutSettings($statements)),
            ),
            new RentSlip($statements),
            new RentPayment(),
            new LateFee($ledger, $statements),
            $this->createStub(\PerfectConsign\Bootstrap\SaleCeiling::class),
        );
        $rent = new RentStore($statements, $this->mall($pdo, $statements), new RentLines(), $ledger);
        $pdo->beginTransaction();
        try {
            $statements->change('UPDATE mall SET rent_prepaid_from_prior_sales = false', []);
            $vendorId = $ledger->newId();
            $tag = substr(str_replace('-', '', $vendorId), 0, 6);
            $statements->change(
                'INSERT INTO vendors (id, code, first_name, last_name) VALUES (?::uuid, ?, ?, ?)',
                [$vendorId, 'c' . $tag, 'Sam', 'Sun']
            );
            $boothId = $this->booth($statements, $ledger, 'q' . $tag, 9000);
            $this->assign($statements, $boothId, $vendorId, '2026-06-15', null);
            $charged = $rent->chargeAmount($boothId, '2026-06-15');
            self::assertSame(4800, $charged);
            $statements->change('UPDATE mall SET rent_prepaid_from_prior_sales = true', []);
            $preview = $receipts->explain($vendorId, $charged, '2026-06-20');
            self::assertNotSame([], $preview);
            $dates = array_map(static fn (array $line): string => $line['date'], $preview);
            self::assertContains('06-15-2026 through 06-30-2026', $dates);
            $statements->change('UPDATE mall SET rent_prepaid_from_prior_sales = false', []);
            $rent->postCharge($boothId, '2026-06-15');
            $explained = $receipts->explain($vendorId, $charged, '2026-06-20');
            self::assertNotSame([], $explained);
            $sessionId = $this->session($statements, $ledger);
            $before = $this->expected($statements, $sessionId);
            // packFigure( openFigure( postReceipt( credits( tenders( requireCheck( handed( debitLines( minor( dollars( rollbackOwned( party( tenderLines( tenderName( checkOn( boothOf( figures( form( rates( filled( sessionStale( older( links( ids( writeJournal( storeTendered( abortJournal( due( owed( amount( begin( lateCharged( apply( parts( cash( card( check( split( share( charges( moves( uncovered( clearFees( unpaid( take( commitOwned( vendorOn( owedOn( vendorName( openBooths( picked( codes( booths( assigned( checked( filled(
            $figure = $receipts->figure('', $boothId, '2026-06-15');
            self::assertNotNull($figure);
            self::assertSame(4800, $figure['owed']);
            self::assertSame(4800, $figure['rent']);
            self::assertSame(0, $figure['late']);
            self::assertSame('48.00', $figure['collect']);
            (new LateFee($ledger, $statements))->post($boothId, '2026-06', '2026-06-15', 500);
            $withFee = $receipts->figure('', $boothId, '2026-06-15');
            self::assertNotNull($withFee);
            self::assertSame(5300, $withFee['owed']);
            self::assertSame(4800, $withFee['rent']);
            self::assertSame(500, $withFee['late']);
            $journalId = $receipts->collect($vendorId, $boothId, '2026-06-15', $sessionId, '10.00', [
                'cash' => '10.00',
                'card' => '',
                'checks' => '',
                'tendered' => '20.00',
                'check' => '',
            ]);
            $slip = $receipts->read($journalId);
            self::assertNotNull($slip);
            self::assertSame(1000, $slip['amount']);
            self::assertSame(1000, $slip['cash']);
            self::assertSame('', $slip['check_number']);
            self::assertGreaterThan(0, $slip['number']);
            self::assertSame(1000, $receipts->change('20.00', $slip['cash']));
            self::assertNull($receipts->change('', $slip['cash']));
            self::assertSame($before + 1000, $this->expected($statements, $sessionId));
            $checkId = $receipts->collect($vendorId, $boothId, '2026-06-15', $sessionId, '4.00', [
                'cash' => '',
                'card' => '',
                'checks' => '4.00',
                'tendered' => '',
                'check' => '1001',
            ]);
            $check = $receipts->read($checkId);
            self::assertNotNull($check);
            self::assertSame(400, $check['amount']);
            self::assertSame(0, $check['cash']);
            self::assertSame('1001', $check['check_number']);
            self::assertSame($before + 1000, $this->expected($statements, $sessionId));
            try {
                $receipts->collect($vendorId, $boothId, '2026-06-15', $sessionId, '50.00', [
                    'cash' => '50.00',
                    'card' => '',
                    'checks' => '',
                    'tendered' => '50.00',
                    'check' => '',
                ]);
                self::fail('a rent receipt above the open rent was accepted');
            } catch (DatabaseRefusal $refusal) {
                self::assertSame('a rent receipt cannot exceed the open rent', $refusal->getMessage());
            }
            try {
                $receipts->collect($vendorId, $boothId, '2026-06-15', $sessionId, '1.00', [
                    'cash' => '',
                    'card' => '',
                    'checks' => '1.00',
                    'tendered' => '',
                    'check' => '',
                ]);
                self::fail('a rent check without a number was accepted');
            } catch (DatabaseRefusal $refusal) {
                self::assertSame('a rent check needs a check number', $refusal->getMessage());
            }
            try {
                $receipts->collect($vendorId, $boothId, '2026-06-15', $sessionId, '', [
                    'way' => 'card',
                    'cash' => '',
                    'card' => '100.00',
                    'checks' => '',
                    'tendered' => '',
                    'check' => '',
                ]);
                self::fail('a card rent receipt above the open rent was accepted');
            } catch (DatabaseRefusal $refusal) {
                self::assertSame('a rent receipt cannot exceed the open rent', $refusal->getMessage());
            }
            $splitId = $receipts->collect($vendorId, $boothId, '2026-06-15', $sessionId, '', [
                'way' => 'split',
                'cash' => '1.00',
                'card' => '2.00',
                'checks' => '',
                'tendered' => '5.00',
                'check' => '',
            ]);
            $split = $receipts->read($splitId);
            self::assertNotNull($split);
            self::assertSame(300, $split['amount']);
            self::assertSame(100, $split['cash']);
            self::assertSame(400, $receipts->change('5.00', $split['cash']));
            $handedId = $receipts->collect($vendorId, $boothId, '2026-06-15', $sessionId, '', [
                'way' => 'cash',
                'cash' => '',
                'card' => '',
                'checks' => '',
                'tendered' => '50.00',
                'check' => '',
            ]);
            $handed = $receipts->read($handedId);
            self::assertNotNull($handed);
            self::assertSame(3600, $handed['amount']);
            self::assertSame(3600, $handed['cash']);
            self::assertSame(1400, $receipts->change('50.00', $handed['cash']));
            self::assertSame($before + 4700, $this->expected($statements, $sessionId));
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    public function testOnePaymentClearsTheOldestRentAcrossBooths(): void
    {
        $root = dirname(__DIR__, 3);
        ProbeMall::ensure($root);
        $pdo = $this->connection();
        $pdo->exec('SET search_path TO ' . $this->schema($pdo) . ', pg_catalog, extensions');
        $statements = new Statements($pdo);
        $ledger = new LedgerStore($pdo, $statements, new LedgerPeriods($pdo), new JournalCash($pdo));
        $runs = new PayoutRunStore($statements, new OccupiedPack($statements));
        $payouts = new PayoutStore($pdo, $statements, $runs, new PayoutWindow());
        $settings = new MallPayoutSettings($statements);
        $fees = new LateFee($ledger, $statements);
        $receipts = new RentReceipt(
            $pdo,
            $statements,
            $payouts,
            $settings,
            new RentPosting(
                $ledger,
                $payouts,
                new PayoutSettler(
                    $ledger,
                    $payouts,
                    $runs,
                    new PayoutRunLines($statements),
                    QuietAudit::make($statements),
                    new VendorClawback($ledger, $statements),
                ),
                $settings,
                new RentMoves($statements, $settings),
            ),
            new RentSlip($statements),
            new RentPayment(),
            $fees,
            $this->createStub(\PerfectConsign\Bootstrap\SaleCeiling::class),
        );
        $rent = new RentStore($statements, $this->mall($pdo, $statements), new RentLines(), $ledger);
        $pdo->beginTransaction();
        try {
            $statements->change('UPDATE mall SET rent_prepaid_from_prior_sales = false', []);
            $vendorId = $ledger->newId();
            $tag = substr(str_replace('-', '', $vendorId), 0, 6);
            $statements->change(
                'INSERT INTO vendors (id, code, first_name, last_name) VALUES (?::uuid, ?, ?, ?)',
                [$vendorId, 'd' . $tag, 'Ada', 'Lane']
            );
            $olderBooth = $this->booth($statements, $ledger, 'a' . $tag, 15000);
            $newerBooth = $this->booth($statements, $ledger, 'b' . $tag, 30000);
            $this->assign($statements, $olderBooth, $vendorId, '2026-08-01', 15000);
            $this->assign($statements, $newerBooth, $vendorId, '2026-10-01', 30000);
            $older = $rent->chargeAmount($olderBooth, '2026-08-01');
            $rent->postCharge($olderBooth, '2026-08-01');
            $newer = $rent->chargeAmount($newerBooth, '2026-10-01');
            $rent->postCharge($newerBooth, '2026-10-01');
            self::assertGreaterThan(0, $older);
            self::assertGreaterThan(1, $newer);
            $feeId = $fees->post($olderBooth, '2026-09', '2026-09-15', 2500);
            $whole = $receipts->figure($vendorId, '', '2026-10-01');
            self::assertNotNull($whole);
            self::assertSame($older + $newer + 2500, $whole['owed']);
            self::assertStringContainsString(', ', $whole['booth']);
            $fromBooth = $receipts->figure('', $newerBooth, '2026-10-01');
            self::assertNotNull($fromBooth);
            self::assertSame($whole['owed'], $fromBooth['owed']);
            self::assertSame($whole['vendor_id'], $fromBooth['vendor_id']);
            $sessionId = $this->session($statements, $ledger);
            $extra = min(5000, $newer - 1);
            $blank = ['way' => 'split', 'cash' => '', 'card' => '', 'checks' => '', 'tendered' => '', 'check' => ''];
            $receipts->collect($vendorId, '', '2026-10-01', $sessionId, '', [
                'way' => 'split',
                'cash' => $this->dollars($older + $extra),
                'card' => '',
                'checks' => '',
                'tendered' => '',
                'check' => '',
            ]);
            self::assertSame(2500, $this->openOn($statements, $olderBooth));
            self::assertSame($newer - $extra, $this->openOn($statements, $newerBooth));
            self::assertSame('', $receipts->collect($vendorId, '', '2026-10-01', '', '', $blank, [$feeId], 'Rain check'));
            $noted = $statements->bound(
                "SELECT description FROM journals WHERE source_type = 'late_fee' AND description LIKE 'Late fee waived.%' ORDER BY journal_no DESC LIMIT 1",
                []
            );
            self::assertSame('Late fee waived. Rain check', RowValue::string($noted[0]['description'] ?? null));
            self::assertSame(0, $this->openOn($statements, $olderBooth));
            try {
                $receipts->collect($vendorId, '', '2026-10-01', '', '', $blank, ['00000000-0000-7000-8000-000000000099'], 'Nope');
                self::fail('a fee that is not on this bill was waived');
            } catch (DatabaseRefusal $refusal) {
                self::assertSame('Choose a late fee.', $refusal->getMessage());
            }
            $rest = $newer - $extra;
            $receipts->collect($vendorId, '', '2026-10-01', $sessionId, '', [
                'way' => 'split',
                'cash' => $this->dollars($rest),
                'card' => '',
                'checks' => '',
                'tendered' => '',
                'check' => '',
            ]);
            $partialId = $fees->post($newerBooth, '2026-11', '2026-11-01', 2500);
            $receipts->collect($vendorId, '', '2026-11-01', $sessionId, '', [
                'way' => 'split',
                'cash' => '10.00',
                'card' => '',
                'checks' => '',
                'tendered' => '',
                'check' => '',
            ]);
            self::assertSame(1500, $this->openOn($statements, $newerBooth));
            try {
                $receipts->collect($vendorId, '', '2026-11-01', '', '', $blank, [$partialId], 'Too late');
                self::fail('a partly paid late fee was waived');
            } catch (DatabaseRefusal $refusal) {
                self::assertSame('Choose a late fee.', $refusal->getMessage());
            }
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
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

    private function booth(Statements $statements, LedgerStore $ledger, string $code, int $rate): string
    {
        $id = $ledger->newId();
        $statements->change(
            'INSERT INTO booths (id, code, name) VALUES (?::uuid, ?, ?)',
            [$id, $code, 'Booth ' . $code]
        );
        $statements->change(
            'INSERT INTO booth_rent_rates (booth_id, amount_minor) VALUES (?::uuid, ?)',
            [$id, $rate]
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
        $amount = $override;
        if ($amount === null) {
            $rate = $statements->bound(
                'SELECT amount_minor FROM booth_rent_rates WHERE booth_id = ?::uuid',
                [$boothId]
            );
            $amount = RowValue::integer($rate[0]['amount_minor'] ?? null);
        }
        $statements->change(
            'INSERT INTO assignment_rent (assignment_id, amount_minor) VALUES (?::uuid, ?)',
            [$id, $amount]
        );
    }

    private function session(Statements $statements, LedgerStore $ledger): string
    {
        $open = $statements->rows(
            'SELECT id::text AS id FROM register_sessions WHERE closed_at IS NULL ORDER BY opened_at LIMIT 1'
        );
        if ($open !== []) {
            return RowValue::string($open[0]['id'] ?? null);
        }
        $id = $ledger->newId();
        $statements->change(
            "INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
             VALUES (?::uuid, '01900000-0000-7000-8000-000000000d10'::uuid, ?, ?::jsonb, 0)",
            [$id, 'Ada', '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}']
        );

        return $id;
    }

    private function openOn(Statements $statements, string $boothId): int
    {
        $rows = $statements->bound(
            "SELECT COALESCE(sum(line.debit_minor - line.credit_minor), 0) AS open_minor
             FROM journal_lines AS line
             JOIN chart_roles AS role ON role.account_id = line.account_id
             WHERE role.role = 'booth_rent_receivable'
               AND line.subledger_type = 'booth'
               AND line.subledger_ref = ?",
            [$boothId]
        );

        return RowValue::integer($rows[0]['open_minor'] ?? null);
    }

    private function dollars(int $minor): string
    {
        return intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    private function expected(Statements $statements, string $sessionId): int
    {
        $rows = $statements->bound('SELECT stall_register_expected_cash(?::uuid) AS cash', [$sessionId]);

        return RowValue::integer($rows[0]['cash'] ?? null);
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

    private function mall(PDO $pdo, Statements $statements): MallSettings
    {
        return new MallSettings($statements, new CompanyStore($statements, new DatabaseRole($pdo), $pdo, new CompanyLines()));
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
