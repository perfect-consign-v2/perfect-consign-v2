<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Coverage;

use DateTimeImmutable;
use DateTimeZone;
use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Feature\Brand\BrandStore;
use PerfectConsign\Feature\Catalog\NameBoard;
use PerfectConsign\Feature\Catalog\NameList;
use PerfectConsign\Feature\Catalog\NameListStore;
use PerfectConsign\Feature\Dashboard\DashboardController;
use PerfectConsign\Feature\Dashboard\DashboardStore;
use PerfectConsign\Feature\Dashboard\RentDue;
use PerfectConsign\Feature\Export\ExportStore;
use PerfectConsign\Feature\Inventory\ItemCellContext;
use PerfectConsign\Feature\Inventory\ItemCells;
use PerfectConsign\Feature\Inventory\ItemForm;
use PerfectConsign\Feature\Inventory\ItemGuard;
use PerfectConsign\Feature\Inventory\ItemPageFilter;
use PerfectConsign\Feature\Inventory\ItemPageQuery;
use PerfectConsign\Feature\Inventory\ItemPageRead;
use PerfectConsign\Feature\Inventory\ItemPageText;
use PerfectConsign\Feature\Inventory\ItemPageWindow;
use PerfectConsign\Feature\Inventory\ItemQueries;
use PerfectConsign\Feature\Inventory\ItemStock;
use PerfectConsign\Feature\Inventory\StoreTake;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Feature\Mall\MallWrite;
use PerfectConsign\Feature\Party\BoothListStore;
use PerfectConsign\Feature\Signup\OwnerEmailVerification;
use PerfectConsign\Feature\Vendor\VendorAccount;
use PerfectConsign\Feature\Vendor\VendorItemDraft;
use PerfectConsign\Feature\Vendor\VendorPlacement;
use PerfectConsign\Feature\Vendor\VendorPortal;
use PerfectConsign\Feature\Vendor\VendorPortalAccess;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\CatalogLinks;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\ResponseSent;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Jobs\JobDispatch;
use PerfectConsign\Shared\Jobs\JobQueue;
use PerfectConsign\Shared\Jobs\JobRunner;
use PerfectConsign\Shared\Tenant\TenantRecord;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

final class GapLineCoverageTest extends TestCase
{
    private const ID = '01900000-0000-7000-8000-0000000000b1';

    private const OTHER = '01900000-0000-7000-8000-0000000000b2';

    public function testItemTextCoversEveryColumnAndRejectsAnUnknownOne(): void
    {
        $today = '2026-11-17';
        foreach (['code', 'barcode', 'description', 'brand', 'color', 'category', 'size', 'condition', 'department', 'quantity'] as $key) {
            [$sql, $params] = ItemPageText::expression($key, $today);
            self::assertNotSame('', $sql);
            self::assertSame([], $params);
        }
        foreach (['vendor', 'intake_on', 'expires_on', 'status', 'take', 'price'] as $key) {
            [$sql, $params] = ItemPageText::expression($key, $today);
            self::assertNotSame('', $sql);
            self::assertSame([], $params);
        }
        [$days, $params] = ItemPageText::expression('days', $today);
        self::assertStringContainsString('intake_on', $days);
        self::assertSame([$today], $params);
        self::assertStringContainsString('DESC', ItemPageText::daysOrder('DESC'));
        self::assertStringContainsString('ASC', ItemPageText::statusOrder('ASC'));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('That column cannot be filtered.');
        ItemPageText::expression('nope', $today);
    }

    public function testStockSqlNamesTheFloorSegments(): void
    {
        self::assertStringContainsString('quantity > 0', ItemStock::sql('available'));
        self::assertStringContainsString('quantity = 0', ItemStock::sql('out'));
        self::assertStringContainsString('sticker_printed_at', ItemStock::sql('unprinted'));
        self::assertSame('', ItemStock::sql(''));
    }

    public function testAPageWindowAcceptsExportAndRefusesABadStart(): void
    {
        $export = ItemPageWindow::from(new Request('GET', '/demo/inventory/rows', ['export' => '1', 'draw' => '3'], []));
        self::assertSame(ItemPageWindow::ALL, $export->mode);
        self::assertSame(3, $export->draw);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('That page is not available.');
        ItemPageWindow::from(new Request('GET', '/demo/inventory/rows', ['start' => 'nope'], []));
    }

    public function testColumnFiltersSkipJunkAndKeepARealColumn(): void
    {
        $query = ItemPageQuery::from(new Request('GET', '/demo/inventory/rows', [
            'columns' => [
                'skip',
                ['name' => 4, 'search' => ['value' => 'red']],
                ['name' => 'code', 'search' => ['value' => '   ']],
                ['name' => 'missing', 'search' => ['value' => 'hat']],
                ['name' => 'code', 'search' => ['value' => ' Hat ']],
            ],
        ], []), '', '', '2026-11-17', ItemPageQuery::SORTS);
        self::assertSame(['code' => 'hat'], $query->filter->columns);
    }

    public function testASearchPastTheLimitIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('That search is too long.');
        ItemPageQuery::from(
            new Request('GET', '/demo/inventory/rows', ['q' => str_repeat('a', 201)], []),
            '',
            '',
            '2026-11-17',
            ItemPageQuery::SORTS,
        );
    }

    public function testSortDirectionMustBeTextAndTheColumnMustResolve(): void
    {
        $refused = new Request('GET', '/demo/inventory/rows', [
            'order' => [['dir' => 1, 'name' => 'code']],
        ], []);
        try {
            ItemPageQuery::from($refused, '', '', '2026-11-17', ItemPageQuery::SORTS);
            self::fail('a non-text direction was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('That column cannot be sorted.', $error->getMessage());
        }
        $missing = ItemPageQuery::from(new Request('GET', '/demo/inventory/rows', [
            'order' => [['dir' => 'asc', 'name' => '   ', 'column' => ['nope']]],
            'columns' => [],
        ], []), '', '', '2026-11-17', ItemPageQuery::SORTS);
        self::assertSame('code', $missing->sort);
        $absent = ItemPageQuery::from(new Request('GET', '/demo/inventory/rows', [
            'order' => [['dir' => 'desc', 'column' => '0']],
            'columns' => ['code'],
        ], []), '', '', '2026-11-17', ItemPageQuery::SORTS);
        self::assertSame('code', $absent->sort);
        self::assertSame('desc', $absent->direction);
        $unnamed = ItemPageQuery::from(new Request('GET', '/demo/inventory/rows', [
            'order' => [['dir' => 'asc', 'column' => 0]],
            'columns' => [['name' => 4]],
        ], []), '', '', '2026-11-17', ItemPageQuery::SORTS);
        self::assertSame('code', $unnamed->sort);
    }

    public function testTheConstructorRefusesASortThatIsNotOnTheList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('That column cannot be sorted.');
        new ItemPageQuery($this->filter(), 'nope', 'asc', new ItemPageWindow(0, 10, ItemPageWindow::PAGE, 0), '2026-11-17');
    }

    public function testAnItemPageReadsDaysStatusSearchAndAClampedStart(): void
    {
        $read = new ItemPageRead($this->pageRows());
        $plain = $read->page($this->query($this->filter(), 'code', 0, 10));
        self::assertSame(5, $plain->total);
        self::assertSame([], $plain->rows);
        $days = $read->page($this->query($this->filter('hat', ['code']), 'days', 0, 10));
        self::assertSame(5, $days->filtered);
        $status = $read->page($this->query($this->filter(), 'status', 0, 10));
        self::assertSame(5, $status->total);
        $skipped = $read->page($this->query($this->filter('hat', ['not-a-column']), 'code', 0, 10));
        self::assertSame(5, $skipped->filtered);
        $clamped = $read->page($this->query($this->filter(), 'code', 40, 10));
        self::assertSame(0, $clamped->start);
        $emptyLength = $read->page($this->query($this->filter(), 'code', 40, 0));
        self::assertSame(0, $emptyLength->start);
        self::assertSame(4, $read->place($this->query($this->filter(), 'code', 0, 10), self::ID));
    }

    public function testASortMissingFromTheOrderMapIsRefused(): void
    {
        $query = (new ReflectionClass(ItemPageQuery::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(ItemPageQuery::class, 'filter'))->setValue($query, $this->filter());
        (new ReflectionProperty(ItemPageQuery::class, 'sort'))->setValue($query, 'nope');
        (new ReflectionProperty(ItemPageQuery::class, 'direction'))->setValue($query, 'asc');
        (new ReflectionProperty(ItemPageQuery::class, 'window'))->setValue($query, new ItemPageWindow(0, 10, ItemPageWindow::PAGE, 0));
        (new ReflectionProperty(ItemPageQuery::class, 'today'))->setValue($query, '2026-11-17');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('That column cannot be sorted.');
        (new ItemPageRead($this->pageRows()))->page($query);
    }

    public function testAnItemSearchUsesTheBoundList(): void
    {
        $rows = new GapStatements(new PDO('sqlite::memory:'));
        $rows->boundRows = [['id' => self::ID, 'code' => 'A1']];
        $listed = (new ItemQueries($rows))->items('hat', 'available', self::ID);
        self::assertSame('A1', $listed[0]['code'] ?? null);
    }

    public function testAnIntegerBarcodeRendersAsText(): void
    {
        $cells = (new ItemCells())->line(
            ['barcode' => 12],
            [['key' => 'barcode']],
            new ItemCellContext('demo', 'csrf', '', '', '', 'vendors', ''),
            'staff',
        );
        self::assertSame('12', $cells[0]);
    }

    public function testACatalogTableOutsideTheNameListIsRefused(): void
    {
        $guard = new ItemGuard(new GapStatements(new PDO('sqlite::memory:')));
        $present = new ReflectionMethod(ItemGuard::class, 'present');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('that catalog table is not a name');
        $present->invoke($guard, 'not_names', 'brand', self::ID, 'use_brand');
    }

    public function testNameAndBrandStoresRefuseTheMissingAndInUsePaths(): void
    {
        $list = NameList::fromPath('colors');
        self::assertInstanceOf(NameList::class, $list);
        $missing = new GapStatements(new PDO('sqlite::memory:'));
        $missing->affectedCount = 0;
        $names = new NameListStore($missing, new CatalogLinks($missing));
        $brands = new BrandStore($missing, new CatalogLinks($missing));
        $this->refusals($names, $brands, $list);
        $held = new GapStatements(new PDO('sqlite::memory:'));
        $id = self::ID;
        $held->bound = static function (string $sql): array {
            if (str_contains($sql, 'pg_constraint')) {
                return [['relation' => 'public.items', 'column_name' => 'color_id']];
            }

            return [];
        };
        $held->rowsOf = static function (string $sql) use ($id): array {
            if (str_contains($sql, 'SELECT DISTINCT')) {
                return [['id' => $id]];
            }

            return [];
        };
        $this->expectInvalid(
            static fn (): mixed => (new NameListStore($held, new CatalogLinks($held)))->remove($list, self::ID),
            'That color is in use.',
        );
        $this->expectInvalid(
            static fn (): mixed => (new BrandStore($held, new CatalogLinks($held)))->remove(self::ID),
            'That brand is in use.',
        );
    }

    public function testACatalogMergeRefusesTheSameIdAndABadId(): void
    {
        $links = new CatalogLinks(new GapStatements(new PDO('sqlite::memory:')));
        $this->expectInvalid(
            static fn (): mixed => $links->retarget('brands', self::ID, self::ID),
            'that catalog id is not a name',
        );
        $this->expectInvalid(
            static fn (): mixed => $links->retarget('brands', 'nope', self::ID),
            'that catalog id is not a name',
        );
    }

    public function testAMergeWithNoOtherNameStaysClosed(): void
    {
        $opened = NameBoard::merge([['id' => self::ID, 'name' => 'Only']], self::ID);
        self::assertSame('', $opened['id']);
        self::assertSame([], $opened['choices']);
    }

    public function testMergeModalsRenderTheKeepList(): void
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        $choices = [
            ['id' => self::OTHER, 'name' => 'Kept'],
            ['id' => 4, 'name' => 5],
        ];
        $brands = $this->capture($view, 'Feature/Brand/brands', [
            'slug' => 'demo',
            'csrf' => 'csrf',
            'query' => '',
            'standing' => 'active',
            'activeCount' => 0,
            'inactiveCount' => 0,
            'total' => 0,
            'confirm' => '',
            'merge' => self::ID,
            'mergeSentence' => 'Keep one brand.',
            'mergeChoices' => $choices,
            'modal' => '',
            'draft' => '',
            'brands' => [],
        ]);
        self::assertStringContainsString('id="brand-merge"', $brands);
        self::assertStringContainsString('Kept', $brands);
        $list = NameList::fromPath('colors');
        self::assertInstanceOf(NameList::class, $list);
        $names = $this->capture($view, 'Feature/Catalog/names', [
            'slug' => 'demo',
            'csrf' => 'csrf',
            'query' => '',
            'standing' => 'active',
            'activeCount' => 0,
            'inactiveCount' => 0,
            'total' => 0,
            'confirm' => '',
            'merge' => self::ID,
            'mergeSentence' => 'Keep one color.',
            'mergeChoices' => $choices,
            'modal' => '',
            'draft' => '',
            'list' => $list,
            'rows' => [],
        ]);
        self::assertStringContainsString('id="name-merge"', $names);
        self::assertStringContainsString('Kept', $names);
    }

    public function testAnInactiveBoothOffersMakeActive(): void
    {
        $html = $this->capture(new View(dirname(__DIR__, 3) . '/src'), 'Feature/Party/booth-form', [
            'slug' => 'demo',
            'csrf' => 'csrf',
            'booth' => ['id' => self::ID, 'code' => 'A1'],
            'defaultWidth' => 10,
            'defaultDepth' => 10,
            'showStanding' => true,
            'boothActive' => false,
            'canRetire' => false,
        ]);
        self::assertStringContainsString('Make active', $html);
        self::assertStringContainsString('value="1"', $html);
    }

    public function testAVendorItemListFillsTheOptionalCells(): void
    {
        $rows = new GapStatements(new PDO('sqlite::memory:'));
        $rows->boundRows = [[
            'barcode' => '',
            'quantity' => '3',
            'intake_on' => '',
            'expires_on' => 1,
            'take_bps' => 1000,
            'commission_bps' => 500,
            'brand_name' => 2,
        ]];
        $account = (new ReflectionClass(VendorAccount::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(VendorAccount::class, 'inventory'))->setValue($account, new ItemQueries($rows));
        (new ReflectionProperty(VendorAccount::class, 'forms'))->setValue(
            $account,
            (new ReflectionClass(ItemForm::class))->newInstanceWithoutConstructor(),
        );
        (new ReflectionProperty(VendorAccount::class, 'take'))->setValue($account, new StoreTake());
        $listed = $account->items(self::ID, 'hat', '');
        self::assertSame('', $listed[0]['barcode']);
        self::assertSame(3, $listed[0]['quantity']);
        self::assertSame('', $listed[0]['expires_on']);
        self::assertSame('', $listed[0]['brand_name']);
        self::assertTrue($listed[0]['overridden']);
        self::assertSame('10', $listed[0]['take_label']);
    }

    public function testVendorPlacementRefusesAnEmptyStoreAndAStoredPlace(): void
    {
        $mall = $this->mall([[
            'booth_rentals_enabled' => false,
            'consignment_enabled' => false,
        ]]);
        $placement = new VendorPlacement(new GapStatements(new PDO('sqlite::memory:')), $mall);
        $this->expectInvalid(
            static fn (): mixed => $placement->forCreate('booth', self::ID, '2026-11-17'),
            'This store is not taking vendor items.',
        );
        $this->expectInvalid(
            static fn (): mixed => $placement->forEdit('store'),
            'Choose where this piece sits.',
        );
    }

    public function testASoldVendorItemAndABlankDescriptionAreRefused(): void
    {
        $draft = $this->draft(true);
        $posted = $this->posted('');
        self::assertTrue($draft->isSoldOut(['id' => self::ID, 'quantity' => 0]));
        $this->expectInvalid(
            static fn (): mixed => $draft->update(['id' => self::ID, 'quantity' => 0, 'placement' => 'booth'], $posted, self::ID),
            'That item is sold.',
        );
        $open = $this->draft(false);
        (new ReflectionProperty(VendorItemDraft::class, 'writer'))->setValue(
            $open,
            (new ReflectionClass(\PerfectConsign\Feature\Inventory\ItemWriter::class))->newInstanceWithoutConstructor(),
        );
        $this->expectInvalid(
            static fn (): mixed => $open->update(
                [
                    'id' => self::ID,
                    'code' => 'A1',
                    'barcode' => '',
                    'quantity' => 1,
                    'placement' => 'booth',
                    'commission_bps' => 1000,
                ],
                $posted,
                self::ID,
            ),
            'Enter a description.',
        );
    }

    public function testAStoredTakeIsCopiedOntoTheDraft(): void
    {
        $draft = $this->draft(false);
        $taken = new ReflectionMethod(VendorItemDraft::class, 'storedTake');
        self::assertSame('10', $taken->invoke($draft, ['commission_bps' => 1000]));
        self::assertSame('', $taken->invoke($draft, ['commission_bps' => null]));
    }

    public function testAVendorCanHoldThePayable(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willReturn(0);
        $statements = new GapStatements($pdo);
        $portal = new VendorPortal($pdo, new DatabaseRole($pdo), $statements);
        $portal->prefer(
            new TenantRecord(self::ID, 'demo', MallDoor::SCHEMA, 'stall_vendor', 'Demo'),
            self::OTHER,
            'hold',
        );
        self::assertStringContainsString('stall_vendor_prefer', $statements->lastChange);
    }

    public function testPortalFlagsRefuseABadSchemaAndAMallWithNoClock(): void
    {
        try {
            VendorPortalAccess::portalEnabledIn(new PDO('sqlite::memory:'), 'public');
            self::fail('a bad schema was accepted');
        } catch (\RuntimeException $error) {
            self::assertSame('the mall schema is not usable', $error->getMessage());
        }
        $pdo = $this->createStub(PDO::class);
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetch')->willReturn(false);
        $pdo->method('prepare')->willReturn($statement);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the mall has no clock');
        VendorPortalAccess::portalEnabledIn($pdo, MallDoor::SCHEMA);
    }

    public function testAMallWithNoClockRefusesVendorFlags(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the mall has no clock');
        $this->mall([])->getVendorFlags();
    }

    public function testVendorPortalFlagsAreRequiredOnAMallSave(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('vendor portal flags are required');
        MallWrite::posted(10, 10, 40, 40, [
            'color' => true,
            'category' => true,
            'size' => true,
            'condition' => true,
            'department' => true,
            'brand' => true,
        ]);
    }

    public function testAPasswordConfirmationMustMatch(): void
    {
        $check = new ReflectionMethod(\PerfectConsign\Feature\Signup\StoreSignup::class, 'normalizedSignupInput');
        $owner = (new ReflectionClass(\PerfectConsign\Feature\Signup\StoreSignup::class))->newInstanceWithoutConstructor();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Password confirmation does not match.');
        $check->invoke($owner, 'Harbor', 'owner@harbor.test', 'long-enough', 'different');
    }

    public function testAnOverdueCountRefusesAFailedPrepare(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn(false);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('job overdue count failed');
        (new JobQueue())->overdue($pdo, 'demo');
    }

    public function testAPlatformMailJobIsDispatched(): void
    {
        $runner = (new ReflectionClass(JobRunner::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(JobRunner::class, 'jobs'))->setValue(
            $runner,
            (new ReflectionClass(JobDispatch::class))->newInstanceWithoutConstructor(),
        );
        $this->expectException(\Error::class);
        $runner->handle([
            'id' => self::ID,
            'tenant' => 'platform',
            'kind' => 'outbound-mail',
            'payload' => '[]',
            'attempts' => 1,
        ]);
    }

    public function testABoothActiveFlagRefusesABadIdAndAMissingRow(): void
    {
        $store = new BoothListStore(new GapStatements(new PDO('sqlite::memory:')));
        $this->expectInvalid(
            static fn (): mixed => $store->setActive('nope', true, '2026-11-17'),
            'that booth is not in this mall',
        );
        $quiet = new GapStatements(new PDO('sqlite::memory:'));
        $quiet->affectedCount = 0;
        $this->expectInvalid(
            static fn (): mixed => (new BoothListStore($quiet))->setActive(self::ID, true, '2026-11-17'),
            'that booth is not in this mall',
        );
    }

    public function testAnExportThatCannotBeReadBackIsRefused(): void
    {
        stream_wrapper_register('gapfail', ClosedExportStream::class);
        try {
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('execute')->willReturn(true);
            $statement->method('fetchAll')->willReturn(['=1+1']);
            $pdo = $this->createStub(PDO::class);
            $pdo->method('prepare')->willReturn($statement);
            $store = new ExportStore($pdo);
            $store->useBuffer('gapfail://export');
            $this->expectException(DatabaseRefusal::class);
            $this->expectExceptionMessage('the export returned a non-text line');
            $store->exportCsv('quickbooks', '2026-11-01', '2026-11-17', '0');
        } finally {
            stream_wrapper_unregister('gapfail');
        }
    }

    public function testTheGreetingUsesTheAfternoonInWhateverZoneHasThatHour(): void
    {
        $zone = $this->afternoonZone();
        $door = MallDoor::open($this, new Request('GET', '/gapmall/dashboard', [], []));
        $door->statements->zone = $zone;
        $controller = new DashboardController(
            $door->app,
            (new ReflectionClass(DashboardStore::class))->newInstanceWithoutConstructor(),
            $door->mall,
            (new ReflectionClass(RentDue::class))->newInstanceWithoutConstructor(),
            new JobQueue(),
            $door->pdo,
        );
        try {
            $controller->show(MallDoor::SLUG);
            self::fail('the dashboard continued without a rent book');
        } catch (\Error) {
            self::assertGreaterThanOrEqual(12, (int) (new DateTimeImmutable('now', new DateTimeZone($zone)))->format('G'));
        }
    }

    public function testASecondOpenSignsTheVendorOutWhenThePortalIsOff(): void
    {
        $door = MallDoor::open($this, new Request('GET', '/gapmall/vendor', [], []));
        $door->bag['actor_role'] = 'vendor';
        $opened = $door->app->open(MallDoor::SLUG, true);
        self::assertSame(MallDoor::SLUG, $opened->slug);
        $door->statements->portal = false;
        $this->expectException(ResponseSent::class);
        $door->app->open(MallDoor::SLUG, true);
    }

    private function afternoonZone(): string
    {
        foreach (DateTimeZone::listIdentifiers() as $zone) {
            $hour = (int) (new DateTimeImmutable('now', new DateTimeZone($zone)))->format('G');
            if ($hour >= 12 && $hour <= 16) {
                return $zone;
            }
        }
        self::fail('no timezone is in the afternoon');
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function mall(array $rows): MallSettings
    {
        $statements = new GapStatements(new PDO('sqlite::memory:'));
        $statements->rowsReturn = $rows;
        $mall = (new ReflectionClass(MallSettings::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(MallSettings::class, 'statements'))->setValue($mall, $statements);

        return $mall;
    }

    private function draft(bool $sold): VendorItemDraft
    {
        $rows = new GapStatements(new PDO('sqlite::memory:'));
        $rows->boundRows = $sold ? [['sold' => 1]] : [];
        $items = (new ReflectionClass(\PerfectConsign\Feature\Inventory\InventoryStore::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(\PerfectConsign\Feature\Inventory\InventoryStore::class, 'statements'))->setValue($items, $rows);
        $draft = (new ReflectionClass(VendorItemDraft::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(VendorItemDraft::class, 'items'))->setValue($draft, $items);
        (new ReflectionProperty(VendorItemDraft::class, 'take'))->setValue($draft, new StoreTake());
        (new ReflectionProperty(VendorItemDraft::class, 'placement'))->setValue(
            $draft,
            (new ReflectionClass(VendorPlacement::class))->newInstanceWithoutConstructor(),
        );

        return $draft;
    }

    /**
     * @return array{
     *     description: string,
     *     price: string,
     *     quantity: string,
     *     intake_on: string,
     *     expires_on: string,
     *     placement: string,
     *     brand_id: string,
     *     brand_label: string,
     *     attributes: array<string, array{id: string, label: string}>
     * }
     */
    private function posted(string $description): array
    {
        return [
            'description' => $description,
            'price' => '10.00',
            'quantity' => '1',
            'intake_on' => '2026-11-17',
            'expires_on' => '',
            'placement' => 'booth',
            'brand_id' => '',
            'brand_label' => '',
            'attributes' => [],
        ];
    }

    private function refusals(NameListStore $names, BrandStore $brands, NameList $list): void
    {
        $this->expectInvalid(
            static fn (): mixed => $names->setActive($list, self::ID, false),
            'That color is not in this mall.',
        );
        $this->expectInvalid(
            static fn (): mixed => $names->remove($list, self::ID),
            'That color is not in this mall.',
        );
        $this->expectInvalid(
            static fn (): mixed => $names->merge($list, self::ID, self::ID),
            'Choose a different color.',
        );
        $this->expectInvalid(
            static fn (): mixed => $names->merge($list, 'nope', self::ID),
            'Choose a different color.',
        );
        $this->expectInvalid(
            static fn (): mixed => $names->merge($list, self::ID, self::OTHER),
            'That color is not in this mall.',
        );
        $found = new GapStatements(new PDO('sqlite::memory:'));
        $found->boundRows = [['id' => self::ID, 'name' => 'Red', 'active' => true]];
        $found->affectedCount = 0;
        $refused = new GapStatements(new PDO('sqlite::memory:'));
        $refused->boundRows = [['id' => self::ID, 'name' => 'Red', 'active' => true]];
        $refused->changeError = new DatabaseRefusal('blocked', 0, null, '23503');
        $this->expectInvalid(
            static fn (): mixed => (new NameListStore($refused, new CatalogLinks($refused)))->merge($list, self::ID, self::OTHER),
            'That color is in use.',
        );
        $this->expectInvalid(
            static fn (): mixed => (new NameListStore($found, new CatalogLinks($found)))->merge($list, self::ID, self::OTHER),
            'That color is not in this mall.',
        );
        $blocked = new GapStatements(new PDO('sqlite::memory:'));
        $blocked->affectedError = new DatabaseRefusal('blocked', 0, null, '23503');
        $this->expectInvalid(
            static fn (): mixed => (new NameListStore($blocked, new CatalogLinks($blocked)))->remove($list, self::ID),
            'That color is in use.',
        );
        $other = new GapStatements(new PDO('sqlite::memory:'));
        $other->affectedError = new DatabaseRefusal('other', 0, null, '23505');
        try {
            (new NameListStore($other, new CatalogLinks($other)))->remove($list, self::ID);
            self::fail('a non-use refusal was swallowed');
        } catch (DatabaseRefusal $error) {
            self::assertSame('23505', $error->sqlState());
        }
        $this->expectInvalid(
            static fn (): mixed => $brands->setActive(self::ID, false),
            'That brand is not in this mall.',
        );
        $this->expectInvalid(
            static fn (): mixed => $brands->merge(self::ID, self::ID),
            'Choose a different brand.',
        );
        $this->expectInvalid(
            static fn (): mixed => $brands->merge('nope', self::ID),
            'Choose a different brand.',
        );
        $brandBlocked = new GapStatements(new PDO('sqlite::memory:'));
        $brandBlocked->boundRows = [['id' => self::ID, 'name' => 'Red', 'active' => true]];
        $brandBlocked->affectedError = new DatabaseRefusal('blocked', 0, null, '23503');
        $this->expectInvalid(
            static fn (): mixed => (new BrandStore($brandBlocked, new CatalogLinks($brandBlocked)))->merge(self::ID, self::OTHER),
            'That brand is in use.',
        );
        $brandOther = new GapStatements(new PDO('sqlite::memory:'));
        $brandOther->affectedError = new DatabaseRefusal('other', 0, null, '23505');
        try {
            (new BrandStore($brandOther, new CatalogLinks($brandOther)))->remove(self::ID);
            self::fail('a non-use brand refusal was swallowed');
        } catch (DatabaseRefusal $error) {
            self::assertSame('23505', $error->sqlState());
        }
        $brandMissing = new GapStatements(new PDO('sqlite::memory:'));
        $brandMissing->affectedCount = 0;
        $this->expectInvalid(
            static fn (): mixed => (new BrandStore($brandMissing, new CatalogLinks($brandMissing)))->remove(self::ID),
            'That brand is not in this mall.',
        );
        $brandGone = new GapStatements(new PDO('sqlite::memory:'));
        $this->expectInvalid(
            static fn (): mixed => (new BrandStore($brandGone, new CatalogLinks($brandGone)))->merge(self::ID, self::OTHER),
            'That brand is not in this mall.',
        );
        $brandKept = new GapStatements(new PDO('sqlite::memory:'));
        $brandKept->boundRows = [['id' => self::ID, 'name' => 'Red', 'active' => true]];
        $brandKept->affectedCount = 0;
        $this->expectInvalid(
            static fn (): mixed => (new BrandStore($brandKept, new CatalogLinks($brandKept)))->merge(self::ID, self::OTHER),
            'That brand is not in this mall.',
        );
    }

    /**
     * @param callable(): mixed $call
     */
    private function expectInvalid(callable $call, string $message): void
    {
        try {
            $call();
            self::fail($message);
        } catch (\InvalidArgumentException $error) {
            self::assertSame($message, $error->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function capture(View $view, string $template, array $data): string
    {
        ob_start();
        try {
            $view->render($template, $data);
        } catch (\Throwable $error) {
            ob_end_clean();
            throw $error;
        }
        $html = ob_get_clean();

        return is_string($html) ? $html : '';
    }

    /**
     * @param list<string> $keys
     */
    private function filter(string $search = '', array $keys = []): ItemPageFilter
    {
        return new ItemPageFilter('', '', '', [], $search, $keys);
    }

    private function query(ItemPageFilter $filter, string $sort, int $start, int $length): ItemPageQuery
    {
        return new ItemPageQuery($filter, $sort, 'asc', new ItemPageWindow($start, $length, ItemPageWindow::PAGE, 0), '2026-11-17');
    }

    private function pageRows(): PageRows
    {
        return new PageRows(new PDO('sqlite::memory:'));
    }
}

final class GapStatements extends Statements
{
    public int $affectedCount = 1;

    public ?\Throwable $affectedError = null;

    public string $lastChange = '';

    /** @var list<array<string, mixed>> */
    public array $boundRows = [];

    /** @var list<array<string, mixed>> */
    public array $rowsReturn = [];

    /** @var (callable(string): list<array<string, mixed>>)|null */
    public $rowsOf = null;

    /** @var (callable(string): list<array<string, mixed>>)|null */
    public $bound = null;

    public ?\Throwable $changeError = null;

    public function change(string $sql, array $params): void
    {
        $this->lastChange = $sql;
        if ($this->changeError !== null) {
            throw $this->changeError;
        }
    }

    public function isolated(callable $work): void
    {
        $work();
    }

    public function affected(string $sql, array $params): int
    {
        if ($this->affectedError !== null) {
            throw $this->affectedError;
        }

        return $this->affectedCount;
    }

    public function rows(string $sql): array
    {
        if ($this->rowsOf !== null) {
            return ($this->rowsOf)($sql);
        }

        return $this->rowsReturn;
    }

    public function bound(string $sql, array $params): array
    {
        if ($this->bound !== null) {
            return ($this->bound)($sql);
        }

        return $this->boundRows;
    }

    public function transaction(callable $work): void
    {
        $work();
    }
}

final class PageRows extends Statements
{
    public function rows(string $sql): array
    {
        if (str_contains($sql, 'count(*)')) {
            return [['total' => 5]];
        }

        return [];
    }

    public function bound(string $sql, array $params): array
    {
        if (str_contains($sql, 'ranked')) {
            return [['place' => '4']];
        }
        if (str_contains($sql, 'count(*)')) {
            return [['total' => 5]];
        }

        return [];
    }

    public function transaction(callable $work): void
    {
        $work();
    }
}

final class ClosedExportStream
{
    public mixed $context = null;

    private string $data = '';

    private bool $rewound = false;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        $this->data .= $data;

        return strlen($data);
    }

    public function stream_read(int $count): string|false
    {
        if ($this->rewound) {
            return false;
        }

        return '';
    }

    public function stream_eof(): bool
    {
        return false;
    }

    public function stream_tell(): int
    {
        return 0;
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        $this->rewound = true;

        return true;
    }

    public function stream_stat(): array
    {
        return [];
    }

    public function stream_flush(): bool
    {
        return true;
    }
}
