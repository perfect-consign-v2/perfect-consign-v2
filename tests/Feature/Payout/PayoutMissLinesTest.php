<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Payout;

use PerfectConsign\Bootstrap\AuditLog;
use PerfectConsign\Bootstrap\JournalAudit;
use PerfectConsign\Bootstrap\SaleCeiling;
use PerfectConsign\Feature\Mall\MallClock;
use PerfectConsign\Feature\Party\PartyStore;
use PerfectConsign\Feature\Payout\CheckWords;
use PerfectConsign\Feature\Payout\OccupiedPack;
use PerfectConsign\Feature\Payout\PayoutController;
use PerfectConsign\Feature\Payout\PayoutDisburser;
use PerfectConsign\Feature\Payout\PayoutOnce;
use PerfectConsign\Feature\Payout\PayoutReverser;
use PerfectConsign\Feature\Payout\PayoutRunChecks;
use PerfectConsign\Feature\Payout\PayoutRunController;
use PerfectConsign\Feature\Payout\PayoutRunLines;
use PerfectConsign\Feature\Payout\PayoutRunSheets;
use PerfectConsign\Feature\Payout\PayoutRunStore;
use PerfectConsign\Feature\Payout\PayoutSettler;
use PerfectConsign\Feature\Payout\PayoutSingle;
use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Feature\Payout\PayoutWindow;
use PerfectConsign\Feature\Payout\PrintedChecks;
use PerfectConsign\Feature\Payout\RentShortfall;
use PerfectConsign\Feature\Payout\VendorClawback;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Tests\Feature\Coverage\CapturesPage;
use PerfectConsign\Tests\Feature\Coverage\MallDoor;
use PerfectConsign\Tests\Feature\Coverage\RoutedStatements;
use PerfectConsign\Tests\Feature\Coverage\ScriptedStatements;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class PayoutMissLinesTest extends TestCase
{
    use CapturesPage;

    private const RUN = MallDoor::RUN;

    private const VENDOR = MallDoor::VENDOR;

    public function testOneVendorWithANonTextIdLeavesTheSelectionBlank(): void
    {
        $door = MallDoor::open($this, new Request('GET', '/' . MallDoor::SLUG . '/payout', [], []));
        $statements = new VendorRows($this->createStub(PDO::class));
        $html = $this->capture(function () use ($door, $statements): void {
            $this->singlePage($door, $statements)->form(MallDoor::SLUG);
        });

        self::assertStringContainsString('No open payout', $html);
    }

    public function testAForeignDraftCollisionIsRethrown(): void
    {
        $statements = new RoutedStatements($this->createStub(PDO::class));
        $error = new PDOException('foreign');
        $error->errorInfo = ['23503', null, 'foreign'];
        $statements->boundError = $error;
        $statements->boundErrorSql = 'INSERT INTO payout_runs';
        $runs = new PayoutRunStore($statements, new OccupiedPack($statements));

        $this->expectException(PDOException::class);
        $runs->ensureDraft('2026-11-01', '2026-11-30', '2026-11-01', '2026-11-12');
    }

    public function testClosingARunPostsTheDraftThenTheDisbursement(): void
    {
        $statements = $this->routed();
        $statements->boundRoutes[] = ['match' => "status = 'draft'", 'rows' => [['id' => self::RUN]]];
        $statements->boundRoutes[] = ['match' => 'FROM payout_runs WHERE id', 'rows' => [[
            'tender' => 'check',
            'status' => 'draft',
            'posting_date' => '2026-11-12',
            'rent_month' => '2026-11-01',
        ]]];
        $once = $this->payoutOnce($statements, $this->ledger());

        $this->expectException(DatabaseRefusal::class);
        $once->pay('2026-11-01', '2026-11-30', '2026-11-01', '2026-11-12', 'check', null);
    }

    public function testSettlementLinksAClawbackAndRefusesAShortBooth(): void
    {
        $statements = $this->routed();
        $statements->boundRoutes[] = ['match' => 'credit_minor - line.debit_minor', 'rows' => [['total' => 8]]];
        $statements->boundRoutes[] = ['match' => 'debit_minor - line.credit_minor', 'rows' => [['total' => 5]]];
        $statements->boundRoutes[] = ['match' => 'FROM payout_pack', 'rows' => [$this->packRow()]];
        $ledger = $this->ledger();
        $settler = new PayoutSettler(
            $ledger,
            new PayoutStore(MallDoor::open($this, new Request('GET', '/', [], []))->pdo, $statements, new PayoutRunStore($statements, new OccupiedPack($statements)), new PayoutWindow()),
            new PayoutRunStore($statements, new OccupiedPack($statements)),
            new PayoutRunLines($statements),
            new JournalAudit(MallDoor::open($this, new Request('GET', '/', [], []))->actor, new AuditLog($statements)),
            new VendorClawback($ledger, $statements),
        );

        $clawback = new VendorClawback($ledger, $statements);
        self::assertSame('', $clawback->reclass(self::VENDOR, 0, '2026-11-12', self::RUN));
        self::assertSame(MallDoor::JOURNAL, $clawback->reclass(self::VENDOR, 3, '2026-11-12', self::RUN));
        $settler->close(self::RUN, '2026-11-12');
        $statements->movement = [['booth_id' => MallDoor::DRAWER, 'open_minor' => 1]];
        $this->expectException(DatabaseRefusal::class);
        $settler->settle(self::RUN, self::VENDOR, '2026-11-12', 4);
    }

    public function testASinglePayoutLinksTheClawbackTheRentAndTheSettlement(): void
    {
        $statements = $this->routed();
        $statements->boundRoutes = [
            ['match' => 'credit_minor - line.debit_minor', 'rows' => [['total' => 4]]],
            ['match' => 'debit_minor - line.credit_minor', 'rows' => [['total' => 4]]],
            ['match' => "status = 'draft'", 'rows' => [['id' => self::RUN]]],
            ['match' => 'stall_payout_run_quote', 'rows' => [[
                'payable' => 4,
                'rent' => 0,
                'check_amount' => 0,
                'owe_amount' => 0,
                'settled_minor' => 4,
                'rent_from_sales' => false,
            ]]],
            ['match' => 'INSERT INTO payout_runs', 'rows' => [['id' => MallDoor::JOURNAL]]],
            ['match' => 'FROM payout_missing_rent', 'rows' => [[
                'booth_id' => MallDoor::DRAWER,
                'posting_date' => '2026-11-12',
                'amount' => 4,
                'month' => '2026-11',
            ]]],
        ];
        $statements->movement = [['booth_id' => MallDoor::DRAWER, 'open_minor' => 4]];
        $single = new PayoutSingle(
            $statements,
            new PayoutStore(MallDoor::open($this, new Request('GET', '/', [], []))->pdo, $statements, new PayoutRunStore($statements, new OccupiedPack($statements)), new PayoutWindow()),
            new PayoutSettler(
                $this->ledger(),
                new PayoutStore(MallDoor::open($this, new Request('GET', '/', [], []))->pdo, $statements, new PayoutRunStore($statements, new OccupiedPack($statements)), new PayoutWindow()),
                new PayoutRunStore($statements, new OccupiedPack($statements)),
                new PayoutRunLines($statements),
                new JournalAudit(MallDoor::open($this, new Request('GET', '/', [], []))->actor, new AuditLog($statements)),
                new VendorClawback($this->ledger(), $statements),
            ),
            new PayoutDisburser(
                $this->ledger(),
                new PayoutStore(MallDoor::open($this, new Request('GET', '/', [], []))->pdo, $statements, new PayoutRunStore($statements, new OccupiedPack($statements)), new PayoutWindow()),
                new PayoutRunStore($statements, new OccupiedPack($statements)),
                new PayoutRunLines($statements),
                new PayoutRunChecks($statements),
                new JournalAudit(MallDoor::open($this, new Request('GET', '/', [], []))->actor, new AuditLog($statements)),
            ),
            new PayoutRunLines($statements),
            new PayoutReverser($this->ledger(), $statements),
            new VendorClawback($this->ledger(), $statements),
        );

        self::assertSame(MallDoor::JOURNAL, $single->pay(self::VENDOR, '2026-11-12'));
    }

    public function testPrintingChecksNumbersThemAndShowsARefusal(): void
    {
        $door = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/payouts/checks?run=' . self::RUN, [], [
            'csrf' => 'csrf-token',
            'starting_check' => '0',
        ]));
        $statements = $this->routed();
        $statements->boundRoutes[] = ['match' => 'FROM payout_runs WHERE id', 'rows' => [[
            'tender' => 'check',
            'status' => 'paid',
            'posting_date' => '2026-11-12',
            'rent_month' => '2026-11-01',
        ]]];
        $sheets = new PayoutRunSheets($statements);
        $printed = new PrintedChecks($door->app, new PayoutRunStore($statements, new OccupiedPack($statements)), $sheets, $this->payoutOnce($statements, $this->ledger()));
        $html = $this->capture(function () use ($printed): void {
            $printed->present(MallDoor::SLUG, self::RUN);
        });
        self::assertStringContainsString('Type the number on the first check.', $html);

        $door = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/payouts/checks?run=' . self::RUN, [], [
            'csrf' => 'csrf-token',
            'starting_check' => '12',
        ]));
        $printed = new PrintedChecks($door->app, new PayoutRunStore($statements, new OccupiedPack($statements)), $sheets, $this->payoutOnce($statements, $this->ledger()));
        $message = $printed->present(MallDoor::SLUG, self::RUN);
        self::assertSame('Nobody on this payout is getting a check.', $message);

        $statements->numbered = false;
        $statements->refuseNumber = true;
        $door = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/payouts/checks?run=' . self::RUN, [], [
            'csrf' => 'csrf-token',
            'starting_check' => '12',
        ]));
        $printed = new PrintedChecks($door->app, new PayoutRunStore($statements, new OccupiedPack($statements)), $sheets, $this->payoutOnce($statements, $this->ledger()));
        $html = $this->capture(function () use ($printed): void {
            $printed->present(MallDoor::SLUG, self::RUN);
        });
        self::assertStringContainsString('Those checks are not ready to print.', $html);
    }

    public function testClosePaysAndAFinishedMonthUsesTheSavedPack(): void
    {
        $door = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/payouts/close', [], [
            'csrf' => 'csrf-token',
            'tender' => 'check',
        ]));
        $door->statements->invokeTransaction = false;
        $this->capture(function () use ($door): void {
            $this->runs($door)->close(MallDoor::SLUG);
        });
        self::assertIsString($door->bag['notice'] ?? null);
    }

    public function testAVoidedCheckAndAPaidMonthStopTheRun(): void
    {
        $door = MallDoor::open($this, new Request('GET', '/' . MallDoor::SLUG . '/payouts/report?run=' . self::RUN, [], []));
        $door->statements->voidedChecks = true;
        $this->capture(function () use ($door): void {
            $this->runs($door)->report(MallDoor::SLUG);
        });

        $door = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/payouts/undo', [], [
            'csrf' => 'csrf-token',
            'run_id' => self::RUN,
            'reason' => 'wrong',
        ]));
        $door->statements->voidedChecks = true;
        $html = $this->capture(function () use ($door): void {
            $this->runs($door)->undo(MallDoor::SLUG);
        });
        self::assertStringContainsString('voided', strtolower($html));

        $door = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/payouts/undo', [], [
            'csrf' => 'csrf-token',
            'run_id' => self::RUN,
            'reason' => 'wrong',
        ]));
        $this->capture(function () use ($door): void {
            $this->runs($door)->undo(MallDoor::SLUG);
        });

        $door = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/payouts/close', [], [
            'csrf' => 'csrf-token',
            'tender' => 'check',
        ]));
        $today = $door->mall->today();
        $window = (new PayoutWindow())->window($today, 1);
        $door->statements->paidRun = [
            'id' => self::RUN,
            'run_no' => 4,
            'status' => 'paid',
            'tender' => 'check',
            'sales_from' => $window['from'],
            'sales_to' => $window['to'],
            'rent_month' => $window['rent'],
        ];
        $html = $this->capture(function () use ($door): void {
            $this->runs($door)->close(MallDoor::SLUG);
        });
        self::assertStringContainsString('already paid', $html);

        $door = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/payouts/checks', [], [
            'csrf' => 'csrf-token',
        ]));
        $html = $this->capture(function () use ($door): void {
            $this->runs($door)->checks(MallDoor::SLUG);
        });
        self::assertStringContainsString('missing', strtolower($html));

        $today = $door->mall->today();
        $window = (new PayoutWindow())->window($today, 1);
        $door->statements->paidRun = [
            'id' => self::RUN,
            'run_no' => 4,
            'status' => 'paid',
            'tender' => 'check',
            'sales_from' => $window['from'],
            'sales_to' => $window['to'],
            'rent_month' => $window['rent'],
            'reversed_at' => '',
            'vendor_name' => 'Ada',
            'check_minor' => 0,
            'check_void' => '',
            'reverses_run_id' => '',
        ];
        $html = $this->capture(function () use ($door): void {
            $this->runs($door)->show(MallDoor::SLUG);
        });
        self::assertStringContainsString('payout', $html);
    }

    private function routed(): SheetStatements
    {
        $statements = new SheetStatements($this->createStub(PDO::class));
        $statements->rowRoutes[] = ['match' => 'rent_cycle_day FROM mall', 'rows' => [['rent_cycle_day' => 1]]];
        $statements->rowRoutes[] = ['match' => 'booth_width_ft', 'rows' => [[
            'time_zone' => 'America/Los_Angeles',
            'booth_width_ft' => 10,
            'booth_depth_ft' => 10,
            'floor_width_ft' => 40,
            'floor_depth_ft' => 40,
            'commission_bps' => 0,
            'floor_days' => 60,
            'cash_round_minor' => 0,
            'tax_bps' => 0,
            'use_color' => false,
            'use_category' => false,
            'use_size' => false,
            'use_condition' => false,
            'use_department' => false,
            'use_brand' => false,
            'label_stock' => 'avery_8160',
            'booth_rentals_enabled' => false,
            'rent_cycle_day' => 1,
            'consignment_enabled' => true,
            'store_stock_enabled' => false,
            'receipt_footer' => '',
            'operating_mode' => 'both',
        ]]];

        return $statements;
    }

    private function ledger(): LedgerStore
    {
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('roleAccount')->willReturn(MallDoor::ACCOUNT);
        $ledger->method('post')->willReturn(MallDoor::JOURNAL);

        return $ledger;
    }

    private function payoutOnce(SheetStatements $statements, LedgerStore $ledger): PayoutOnce
    {
        $runs = new PayoutRunStore($statements, new OccupiedPack($statements));
        $lines = new PayoutRunLines($statements);
        $door = MallDoor::open($this, new Request('GET', '/', [], []));
        $audit = new JournalAudit($door->actor, new AuditLog($statements));

        return new PayoutOnce(
            $runs,
            new PayoutRunSheets($statements),
            new PayoutSettler($ledger, new PayoutStore($this->createStub(PDO::class), $statements, $runs, new PayoutWindow()), $runs, $lines, $audit, new VendorClawback($ledger, $statements)),
            new PayoutDisburser($ledger, new PayoutStore($this->createStub(PDO::class), $statements, $runs, new PayoutWindow()), $runs, $lines, new PayoutRunChecks($statements), $audit),
            $statements,
            new CheckWords(),
            new RentShortfall($statements),
            new SaleCeiling($statements, $door->actor, new AuditLog($statements), $door->app->request()),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function packRow(): array
    {
        return [
            'vendor_id' => self::VENDOR,
            'code' => 'A',
            'name' => 'Ada',
            'booth' => '',
            'booth_id' => '',
            'mode' => 'pay',
            'prior' => 0,
            'charged' => 0,
            'paid' => 0,
            'payable' => 0,
            'rent' => 0,
            'month_rent' => 0,
            'check_amount' => 0,
            'owe_amount' => 0,
            'settled_minor' => 0,
            'adjustment_minor' => 0,
        ];
    }

    private function singlePage(MallDoor $door, RoutedStatements $statements): PayoutController
    {
        $runs = new PayoutRunStore($statements, new OccupiedPack($statements));
        $window = new PayoutWindow();
        $payouts = new PayoutStore($door->pdo, $statements, $runs, $window);

        return new PayoutController(
            $door->app,
            $payouts,
            new PartyStore($statements, new JournalAudit($door->actor, new AuditLog($statements)), new \PerfectConsign\Feature\Party\AssignmentBook($statements)),
            new PayoutSingle(
                $statements,
                $payouts,
                new PayoutSettler($door->ledger, $payouts, $runs, new PayoutRunLines($statements), new JournalAudit($door->actor, new AuditLog($statements)), new VendorClawback($door->ledger, $statements)),
                new PayoutDisburser($door->ledger, $payouts, $runs, new PayoutRunLines($statements), new PayoutRunChecks($statements), new JournalAudit($door->actor, new AuditLog($statements))),
                new PayoutRunLines($statements),
                new PayoutReverser($door->ledger, $statements),
                new VendorClawback($door->ledger, $statements),
            ),
            $door->mall,
            $door->register,
            new MallClock($door->mall),
        );
    }

    private function runs(MallDoor $door): PayoutRunController
    {
        $runs = new PayoutRunStore($door->statements, new OccupiedPack($door->statements));
        $sheets = new PayoutRunSheets($door->statements);
        $window = new PayoutWindow();
        $payouts = new PayoutStore($door->pdo, $door->statements, $runs, $window);
        $lines = new PayoutRunLines($door->statements);
        $audit = new JournalAudit($door->actor, new AuditLog($door->statements));
        $settler = new PayoutSettler($door->ledger, $payouts, $runs, $lines, $audit, new VendorClawback($door->ledger, $door->statements));
        $disburser = new PayoutDisburser($door->ledger, $payouts, $runs, $lines, new PayoutRunChecks($door->statements), $audit);
        $once = new PayoutOnce($runs, $sheets, $settler, $disburser, $door->statements, new CheckWords(), new RentShortfall($door->statements), new SaleCeiling($door->statements, $door->actor, new AuditLog($door->statements), $door->app->request()));

        return new PayoutRunController(
            $door->app,
            $door->mall,
            $runs,
            $window,
            $once,
            new PayoutReverser($door->ledger, $door->statements),
            $door->register,
            new MallClock($door->mall),
            new PrintedChecks($door->app, $runs, $sheets, $once),
        );
    }
}

final class VendorRows extends RoutedStatements
{
    /**
     * @return list<array<string, mixed>>
     */
    public function rows(string $sql): array
    {
        if (str_contains($sql, 'FROM vendors AS vendor')) {
            return [['id' => 7, 'code' => 'A', 'name' => 'Ada']];
        }

        return parent::rows($sql);
    }
}

final class SheetStatements extends RoutedStatements
{
    public bool $numbered = false;

    public bool $refuseNumber = false;

    public function transaction(callable $work): void
    {
        if ($this->refuseNumber) {
            throw new DatabaseRefusal('Those checks are not ready to print.');
        }
        $work();
        $this->numbered = true;
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    public function bound(string $sql, array $params): array
    {
        if (str_contains($sql, 'AS check_number')) {
            return [[
                'vendor_id' => MallDoor::VENDOR,
                'code' => 'A',
                'name' => 'Ada',
                'booth' => '',
                'sold_on' => '2026-11-12',
                'item_code' => 'HAT',
                'description' => 'Hat',
                'price_minor' => 100,
                'quantity' => 1,
                'commission_minor' => 0,
                'settled_minor' => 0,
                'check_minor' => $this->numbered ? 0 : 100,
                'owes_minor' => 0,
                'check_number' => $this->numbered ? '12' : '',
            ]];
        }

        return parent::bound($sql, $params);
    }
}
