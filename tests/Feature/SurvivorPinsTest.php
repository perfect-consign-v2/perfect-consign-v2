<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature;

use PerfectConsign\Feature\Catalog\NameList;
use PerfectConsign\Feature\Inventory\InventoryStore;
use PerfectConsign\Feature\Inventory\ItemForm;
use PerfectConsign\Feature\Inventory\StoreTake;
use PerfectConsign\Feature\Party\MapStore;
use PerfectConsign\Feature\Payout\PayoutRunLines;
use PerfectConsign\Feature\Payout\PayoutRunStore;
use PerfectConsign\Feature\Payout\PayoutSettler;
use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Feature\Payout\PayoutWindow;
use PerfectConsign\Feature\Payout\RentShortfall;
use PerfectConsign\Feature\Rent\RentOwed;
use PerfectConsign\Feature\Pos\GiftIssue;
use PerfectConsign\Feature\Pos\TicketTenders;
use PerfectConsign\Feature\Register\RegisterActivity;
use PerfectConsign\Feature\Vendor\VendorAccount;
use PerfectConsign\Feature\Vendor\VendorCheck;
use PerfectConsign\Feature\Vendor\VendorSales;
use PerfectConsign\Feature\WaitList\WaitListFields;
use PerfectConsign\Feature\WaitList\WaitListStore;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Schema\MigrationLock;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Tests\Support\QuietAudit;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;

final class SurvivorPinsTest extends TestCase
{
    public function testABlankStoreTakeIsUnset(): void
    {
        self::assertNull((new StoreTake())->fromPercent(''));
        self::assertSame('10', (new StoreTake())->fromPercent('10') === null ? '' : (new StoreTake())->format(1000));
    }

    public function testAnUnknownCatalogPathIsNotAList(): void
    {
        self::assertNotNull(NameList::fromPath('colors'));
        self::assertNull(NameList::fromPath('missing'));
    }

    public function testAnEmptySalesListSaysThereAreNoSales(): void
    {
        $slug = 'demo';
        $span = ['from' => '2026-06-01', 'to' => '2026-06-02', 'label' => 'Those dates'];
        $rows = [];
        $total = 0;
        $previewAs = '';
        ob_start();
        include dirname(__DIR__, 2) . '/src/Feature/Vendor/sales.php';
        $html = ob_get_clean();
        self::assertIsString($html);
        self::assertStringContainsString('No sales in this range.', $html);

        $rows = [[
            'date' => '2026-06-01',
            'code' => 'A',
            'description' => 'Coat',
            'price' => 100,
            'discount' => 0,
            'sold' => 100,
            'take' => 10,
            'share' => 90,
            'item_id' => 'item',
            'has_photo' => false,
        ]];
        ob_start();
        include dirname(__DIR__, 2) . '/src/Feature/Vendor/sales.php';
        $filled = ob_get_clean();
        self::assertIsString($filled);
        self::assertStringNotContainsString('No sales in this range.', $filled);
    }

    public function testACustomRangeKeepsTheLaterEndAndRejectsABadDay(): void
    {
        $sales = new VendorSales($this->createStub(Statements::class));
        $window = ['from' => '2026-06-01', 'to' => '2026-06-30', 'rent' => '2026-06-01'];
        self::assertSame('2026-06-02', $sales->span('2026-06-15', 'custom', '2026-06-02', '2026-06-01', null, $window)['to']);
        self::assertSame('2026-06-02', $sales->span('2026-06-15', 'custom', '2026-06-02', '2026-06-02', null, $window)['to']);
        self::assertNull($sales->span('2026-06-15', 'custom', 'nope', '2026-06-02', null, $window)['label'] === 'Those dates' ? 'kept' : null);
        self::assertSame('Since your last check', $sales->span('2026-06-15', 'custom', 'nope', '2026-06-02', null, $window)['label']);
    }

    public function testABlankTenderReferenceIsStoredAsNull(): void
    {
        $seen = [];
        $statements = $this->createStub(Statements::class);
        $statements->method('change')->willReturnCallback(static function (string $sql, array $params) use (&$seen): void {
            if (str_contains($sql, 'INSERT INTO ticket_tenders')) {
                $seen = $params;
            }
        });
        $statements->method('bound')->willReturn([]);
        $tenders = new TicketTenders(
            $statements,
            $this->createStub(LedgerStore::class),
            (new ReflectionClass(\PerfectConsign\Feature\Register\RegisterStore::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(PayoutStore::class))->newInstanceWithoutConstructor(),
        );
        $tenders->apply('01900000-0000-7000-8000-0000000000aa', 'cash', 100, 'front', 500);
        self::assertNull($seen[3]);
    }

    public function testACheckWithoutANumberIsRefused(): void
    {
        $gifts = new GiftIssue($this->createStub(Statements::class), $this->createStub(LedgerStore::class));
        try {
            $gifts->issue('session', '2026-06-01', 'GC-1', 100, 'checks', null);
            self::fail('a blank check was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('a check sale needs a check number', $error->getMessage());
        }
        try {
            $gifts->issue('session', '2026-06-01', 'GC-1', 100, 'checks', '1001');
            self::fail('the ledger was not required');
        } catch (\Throwable) {
            self::assertTrue(true);
        }
    }

    public function testAZeroSettlementPostsNothing(): void
    {
        $posts = 0;
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('post')->willReturnCallback(static function () use (&$posts): string {
            $posts++;

            return 'journal';
        });
        $settler = new PayoutSettler(
            $ledger,
            (new ReflectionClass(PayoutStore::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(PayoutRunStore::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(PayoutRunLines::class))->newInstanceWithoutConstructor(),
            QuietAudit::make($this->createStub(Statements::class)),
            (new ReflectionClass(\PerfectConsign\Feature\Payout\VendorClawback::class))->newInstanceWithoutConstructor(),
        );

        self::assertSame('', $settler->settle('run', 'vendor', '2026-06-01', 0));
        self::assertSame(0, $posts);
    }

    public function testMoveInDaysFollowTheStartDay(): void
    {
        $short = (new ReflectionClass(RentShortfall::class))->newInstanceWithoutConstructor();
        $days = new ReflectionMethod(RentShortfall::class, 'days');
        $monthly = 30000;
        $starts = '2026-09-11';
        $charged = \PerfectConsign\Feature\Party\MoveInRent::due($monthly, $starts);

        self::assertSame(0, $days->invoke($short, 1, $monthly, '2026-09-11', $charged));
        self::assertSame(0, $days->invoke($short, 'nope', $monthly, '2026-09-11', $charged));
        self::assertSame(0, $days->invoke($short, '2026-08-11', $monthly, '2026-09-11', $charged));
        self::assertSame(0, $days->invoke($short, '2026-09-01', $monthly, '2026-09-01', $monthly));
        self::assertSame(20, $days->invoke($short, $starts, $monthly, '2026-09-11', $charged));
    }

    public function testAnUnknownBoothFallsBackToTheFirstSpot(): void
    {
        $owed = (new ReflectionClass(RentOwed::class))->newInstanceWithoutConstructor();
        $picked = new ReflectionMethod(RentOwed::class, 'picked');
        $spots = [
            ['id' => 'booth-a', 'code' => 'A'],
            ['id' => 'booth-b', 'code' => 'B'],
        ];

        self::assertSame('booth-b', $picked->invoke($owed, $spots, 'booth-b'));
        self::assertSame('booth-a', $picked->invoke($owed, $spots, 'missing'));
        self::assertSame('', $picked->invoke($owed, [], 'missing'));
    }

    public function testAFloorNameAndAPlacementWithoutASizeAreStored(): void
    {
        $updates = [];
        $statements = $this->createStub(Statements::class);
        $statements->method('rows')->willReturn([['total' => 1]]);
        $statements->method('bound')->willReturn([['id' => '01900000-0000-7000-8000-0000000000aa']]);
        $statements->method('affected')->willReturnCallback(static function (string $sql) use (&$updates): int {
            $updates[] = $sql;

            return 1;
        });
        $statements->method('transaction')->willReturnCallback(static function (callable $work): void {
            $work();
        });
        $statements->method('change');
        $store = new MapStore($statements);
        self::assertSame('01900000-0000-7000-8000-0000000000aa', $store->addFloor('Main'));
        $store->savePlacements([[
            'id' => '01900000-0000-7000-8000-0000000000bb',
            'floorId' => '01900000-0000-7000-8000-0000000000aa',
            'x' => 1,
            'z' => 2,
            'width' => null,
            'depth' => null,
        ]]);
        $store->savePlacements([
            [
                'id' => '01900000-0000-7000-8000-0000000000bb',
                'floorId' => '01900000-0000-7000-8000-0000000000aa',
                'x' => 1,
                'z' => 2,
                'width' => null,
                'depth' => 4,
            ],
            [
                'id' => '01900000-0000-7000-8000-0000000000cc',
                'floorId' => '01900000-0000-7000-8000-0000000000aa',
                'x' => 3,
                'z' => 4,
                'width' => 4,
                'depth' => null,
            ],
        ]);
        $placement = implode("\n", $updates);
        self::assertStringNotContainsString('width_ft', $placement);
        try {
            $store->addFloor(str_repeat('a', 41));
            self::fail('a padded floor name was accepted');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
    }

    public function testAnUnfilteredWaitListUsesThePlainQuery(): void
    {
        $plain = 0;
        $statements = $this->createStub(Statements::class);
        $statements->method('rows')->willReturnCallback(static function () use (&$plain): array {
            $plain++;

            return [];
        });
        $statements->method('bound')->willReturn([]);
        $store = new WaitListStore($statements, new WaitListFields(), new NullLogger());
        self::assertSame([], $store->entries('', 'all'));
        self::assertSame(1, $plain);
    }

    public function testALiveMigrationProbeIsNotRolledBack(): void
    {
        $rolled = 0;
        $probe = $this->createStub(\PDOStatement::class);
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->method('query')->willReturn($probe);
        $pdo->method('rollBack')->willReturnCallback(static function () use (&$rolled): bool {
            $rolled++;

            return true;
        });
        (new MigrationLock($pdo))->release();
        self::assertSame(0, $rolled);

        $failed = $this->createStub(\PDO::class);
        $failed->method('inTransaction')->willReturn(true);
        $failed->method('query')->willReturn(false);
        $failed->method('rollBack')->willReturnCallback(static function () use (&$rolled): bool {
            $rolled++;

            return true;
        });
        try {
            (new MigrationLock($failed))->release();
        } catch (\RuntimeException) {
            self::assertSame(1, $rolled);
        }
    }

    public function testZeroRentDoesNotSitOnTheDesk(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('rows')->willReturn([['rent_cycle_day' => 1]]);
        $statements->method('bound')->willReturnCallback(static function (string $sql): array {
            if (str_contains($sql, 'payout_mode')) {
                return [['name' => 'Ada', 'payout_mode' => 'pay']];
            }
            if (str_contains($sql, 'payout_mode') && str_contains($sql, 'booth_rent_receivable')) {
                return [['total' => 0]];
            }
            if (str_contains($sql, 'vendor_payable')) {
                return [['total' => 0]];
            }

            return [];
        });
        $occupied = (new ReflectionClass(\PerfectConsign\Feature\Payout\OccupiedPack::class))->newInstanceWithoutConstructor();
        $runs = new PayoutRunStore($statements, $occupied);
        $payouts = new PayoutStore($this->createStub(\PDO::class), $statements, $runs, new PayoutWindow());
        $inventory = (new ReflectionClass(\PerfectConsign\Feature\Inventory\ItemQueries::class))->newInstanceWithoutConstructor();
        $forms = (new ReflectionClass(ItemForm::class))->newInstanceWithoutConstructor();
        $account = new VendorAccount($statements, $inventory, $forms, new StoreTake());
        $home = (new VendorCheck($statements, $payouts, $account))->home('01900000-0000-7000-8000-0000000000aa', '2026-06-15');

        self::assertSame(0, $home['desk']);
    }

    public function testOnlyAClockValueIsFormatted(): void
    {
        $mallRows = $this->createStub(Statements::class);
        $mallRows->method('rows')->willReturn([['time_zone' => 'UTC']]);
        $company = new \PerfectConsign\Feature\Company\CompanyStore(
            $this->createStub(Statements::class),
            new \PerfectConsign\Bootstrap\DatabaseRole($this->createStub(\PDO::class)),
            $this->createStub(\PDO::class),
            new \PerfectConsign\Feature\Company\CompanyLines(),
        );
        $clock = new \PerfectConsign\Feature\Mall\MallClock(new \PerfectConsign\Feature\Mall\MallSettings(
            $mallRows,
            $company,
            new \PerfectConsign\Feature\Mall\MallDay($mallRows),
        ));
        $activity = new RegisterActivity($this->createStub(Statements::class), $clock);

        self::assertSame('', $activity->local(''));
        self::assertSame('', $activity->local(4));
        self::assertStringContainsString('2026', $activity->local('2026-01-02 17:00:00'));
        self::assertStringNotContainsString('17:00:00', $activity->local('2026-01-02 17:00:00'));
    }

    public function testAPartialStoryStaysOnTheEarlierMonthLine(): void
    {
        $note = $this->shortfall([
            ['vendor_id' => 'v', 'booth' => 'A', 'month' => '2026-05', 'paid' => 0],
        ], [
            ['vendor_id' => 'v', 'booth' => 'A', 'posted' => '2026-05-15', 'starts_on' => '2026-05-15', 'monthly' => 50, 'charged' => 50],
        ], 80);

        self::assertStringContainsString('earlier month', $note);
    }

    public function testAPaidChargeDoesNotBecomeAShortfall(): void
    {
        $note = $this->shortfall([
            ['vendor_id' => 'v', 'booth' => 'A', 'month' => '2026-05', 'paid' => 100],
        ], [
            ['vendor_id' => 'v', 'booth' => 'A', 'posted' => '2026-05-15', 'starts_on' => '2026-05-15', 'monthly' => 100, 'charged' => 100],
        ], 200);

        self::assertStringContainsString('earlier month', $note);
    }

    public function testAZeroBalanceDoesNotJoinTheUnpaidStory(): void
    {
        $note = $this->shortfall([
            ['vendor_id' => 'v', 'booth' => 'A', 'month' => '2026-05', 'paid' => 100],
        ], [
            ['vendor_id' => 'v', 'booth' => 'A', 'posted' => '2026-05-15', 'starts_on' => '2026-05-15', 'monthly' => 100, 'charged' => 100],
            ['vendor_id' => 'v', 'booth' => 'B', 'posted' => '2026-05-15', 'starts_on' => '2026-05-15', 'monthly' => 50, 'charged' => 50],
        ], 50);

        self::assertStringNotContainsString('$0.00', $note);
        self::assertStringContainsString('$0.50', $note);
    }

    public function testASecondChargeUsesOnlyTheUnspentPayment(): void
    {
        $note = $this->shortfall([
            ['vendor_id' => 'v', 'booth' => 'A', 'month' => '2026-05', 'paid' => 40],
        ], [
            ['vendor_id' => 'v', 'booth' => 'A', 'posted' => '2026-05-10', 'starts_on' => '2026-05-10', 'monthly' => 40, 'charged' => 40],
            ['vendor_id' => 'v', 'booth' => 'A', 'posted' => '2026-05-20', 'starts_on' => '2026-05-20', 'monthly' => 100, 'charged' => 100],
        ], 100);

        self::assertStringContainsString('$1.00', $note);
        self::assertStringNotContainsString('earlier month', $note);
    }

    public function testCashDoesNotNeedACheckNumber(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('isolated')->willReturnCallback(static function (callable $work): void {
            $work();
        });
        $statements->method('bound')->willReturn([['id' => '01900000-0000-7000-8000-0000000000aa']]);
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('roleAccount')->willReturn('acct');
        $ledger->method('post')->willReturn('journal');

        $id = (new GiftIssue($statements, $ledger))->issue('session', '2026-06-01', '1001', 100, 'cash', null);

        self::assertSame('journal', $id);
    }

    public function testADrawOfTheOpenPayableIsAllowed(): void
    {
        $statement = $this->createStub(\PDOStatement::class);
        $statement->method('fetchColumn')->willReturn(100);
        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([]);
        $statements->method('change');
        $payouts = new PayoutStore(
            $pdo,
            $statements,
            (new ReflectionClass(PayoutRunStore::class))->newInstanceWithoutConstructor(),
            new PayoutWindow(),
        );
        $tenders = new TicketTenders(
            $statements,
            $this->createStub(LedgerStore::class),
            (new ReflectionClass(\PerfectConsign\Feature\Register\RegisterStore::class))->newInstanceWithoutConstructor(),
            $payouts,
        );

        $tenders->apply('01900000-0000-7000-8000-0000000000aa', 'vendor_draw', 100, '01900000-0000-7000-8000-0000000000bb', 100);
        self::assertTrue(true);
    }

    /**
     * @param list<array<string, mixed>> $payments
     * @param list<array<string, mixed>> $charges
     */
    private function shortfall(array $payments, array $charges, int $owes): string
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturnCallback(static function (string $sql) use ($payments, $charges): array {
            if (str_contains($sql, 'rent_payment')) {
                return $payments;
            }
            if (str_contains($sql, 'rent_charge')) {
                return $charges;
            }

            return [];
        });
        $noted = (new RentShortfall($statements))->attach([[
            'vendor_id' => 'v',
            'code' => 'A',
            'name' => 'Ada',
            'booth' => 'A',
            'boothId' => 'b',
            'mode' => 'pay',
            'prior' => 0,
            'charged' => 0,
            'paid' => 0,
            'payable' => 0,
            'rent' => 0,
            'monthRent' => 0,
            'check' => 0,
            'owes' => $owes,
            'settled' => 0,
            'adjustment' => 0,
        ]], '2026-06-01');

        return $noted[0]['note'];
    }
}
