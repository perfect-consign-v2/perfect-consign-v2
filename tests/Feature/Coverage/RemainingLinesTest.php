<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Coverage;

use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\AuditLog;
use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Bootstrap\SaleCeiling;
use PerfectConsign\Bootstrap\SignedInPerson;
use PerfectConsign\Feature\Admin\AdminTenantList;
use PerfectConsign\Feature\Backup\BackupFile;
use PerfectConsign\Feature\Backup\BackupWorkspace;
use PerfectConsign\Feature\Backup\CommandProcess;
use PerfectConsign\Feature\Backup\DirectoryTree;
use PerfectConsign\Feature\Backup\PlatformSnapshot;
use PerfectConsign\Feature\Backup\PostgresTools;
use PerfectConsign\Feature\Backup\TenantBackup;
use PerfectConsign\Feature\Brand\BrandController;
use PerfectConsign\Feature\Brand\BrandStore;
use PerfectConsign\Feature\Catalog\NameListController;
use PerfectConsign\Feature\Catalog\NameListStore;
use PerfectConsign\Feature\Company\CompanyLines;
use PerfectConsign\Feature\Company\CompanyStore;
use PerfectConsign\Feature\Dev\DevTenantReset;
use PerfectConsign\Feature\Mall\MallDay;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Feature\Party\PartyIdentity;
use PerfectConsign\Feature\Pos\CustomerController;
use PerfectConsign\Feature\Pos\CustomerDirectory;
use PerfectConsign\Feature\Pos\CustomerStore;
use PerfectConsign\Feature\Party\SellerPaths;
use PerfectConsign\Feature\Pos\LayawayBook;
use PerfectConsign\Feature\Pos\ReceiptChange;
use PerfectConsign\Feature\Pos\ReceiptView;
use PerfectConsign\Feature\Pos\SalePoster;
use PerfectConsign\Feature\Pos\SaleReceipt;
use PerfectConsign\Feature\Pos\SaleReversal;
use PerfectConsign\Feature\Pos\SaleTicketAttach;
use PerfectConsign\Feature\Payout\VendorClawback;
use PerfectConsign\Feature\Report\LedgerReport;
use PerfectConsign\Feature\Report\ReportCatalog;
use PerfectConsign\Feature\Report\ReportCsvController;
use PerfectConsign\Feature\Report\ReportMonth;
use PerfectConsign\Feature\Report\ReportSheetsController;
use PerfectConsign\Feature\Report\ReportsController;
use PerfectConsign\Feature\Report\SalesTaxFiling;
use PerfectConsign\Feature\Report\SavedReport;
use PerfectConsign\Feature\Report\SalesTaxSheet;
use PerfectConsign\Feature\Vendor\VendorPreview;
use PerfectConsign\Feature\WaitList\WaitListFields;
use PerfectConsign\Feature\WaitList\WaitListStore;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalCash;
use PerfectConsign\Ledger\LedgerPeriods;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Database\CatalogLinks;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\ResponseSent;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Tests\Support\ScriptPdo;
use PDO;
use PDOStatement;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

final class RemainingLinesTest extends TestCase
{
    use CapturesPage;

    public function testAMissingSignedInPersonIsRefused(): void
    {
        $this->expectException(DatabaseRefusal::class);
        $this->expectExceptionMessage('the signed-in person is missing');
        SignedInPerson::require('', 'owner');
    }

    public function testABoothMallIsLabeled(): void
    {
        $schema = 'tenant_' . str_repeat('ab', 16);
        $pdo = new ScriptPdo();
        $pdo->onQuery('platform.tenants', [[
            'id' => MallDoor::TENANT,
            'slug' => MallDoor::SLUG,
            'schema_name' => $schema,
            'display_name' => 'Gap',
        ]]);
        $pdo->onQuery('operating_mode', [['operating_mode' => 'booth_mall']]);
        $pdo->onPrepare('platform.owners', []);
        $listed = (new AdminTenantList($pdo, new DatabaseRole($pdo)))->all();

        self::assertSame('Booth mall', $listed[0]['modeLabel'] ?? null);
    }

    public function testAnUnknownPasswordFileStepIsRefused(): void
    {
        $tools = new PostgresTools(new CommandProcess($this->createStub(LoggerInterface::class)));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown password file failure');
        $tools->failPasswordFile('nope');
    }

    public function testABackupDirectoryThatCannotBeRemovedIsLogged(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc-backup-keep-' . bin2hex(random_bytes(4));
        if (!mkdir($root) && !is_dir($root)) {
            self::fail('the backup directory could not be created');
        }
        $child = $root . DIRECTORY_SEPARATOR . 'child';
        if (!mkdir($child) && !is_dir($child)) {
            self::fail('the backup child could not be created');
        }
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');
        $tree = new DirectoryTree();
        $tree->treatAsLink(true);
        $backup = new TenantBackup(
            $logger,
            new PostgresTools(new CommandProcess($logger)),
            new BackupWorkspace($logger, $tree),
            new PlatformSnapshot(),
        );
        set_error_handler(static function (int $severity): bool {
            return $severity === E_WARNING;
        });
        try {
            $backup->delete(new BackupFile('id', $root . '.zip', $root, 1, str_repeat('a', 64), 'mall.zip', '2026-11-13T00:00:00+00:00'));
        } finally {
            restore_error_handler();
            $this->removeTree($child);
            $this->removeTree($root);
        }
    }

    public function testSavedDevOwnersAreRestoredWithoutAWipe(): void
    {
        $captured = [];
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function () use (&$captured): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('execute')->willReturnCallback(function (array $params) use (&$captured): bool {
                $captured = $params;

                return true;
            });
            $statement->method('rowCount')->willReturn(1);

            return $statement;
        });
        $reset = new DevTenantReset();
        $method = new ReflectionMethod(DevTenantReset::class, 'restoreOwners');
        $method->invoke($reset, $pdo, [[
            'username' => 'owner',
            'password_hash' => 'hash',
        ]]);

        self::assertSame(['owner', 'hash', 'dev'], $captured);
    }

    public function testCatalogDeletesThatTheBooksRefuseAreShown(): void
    {
        $statements = new MallRows(true);
        $door = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/brands/' . MallDoor::ACCOUNT . '/delete', [], [
            'csrf' => 'csrf-token',
        ]));
        $mall = $this->mall($statements, $door->pdo);
        $html = $this->capture(function () use ($door, $statements, $mall): void {
            (new BrandController($door->app, new BrandStore($statements, new CatalogLinks($statements)), $mall))
                ->remove(MallDoor::SLUG, MallDoor::ACCOUNT);
        });
        self::assertStringContainsString('the books refused', $html);

        $colors = new MallRows(true);
        $colorDoor = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/colors/' . MallDoor::ACCOUNT . '/delete', [], [
            'csrf' => 'csrf-token',
        ]));
        $colorMall = $this->mall($colors, $colorDoor->pdo);
        $page = $this->capture(function () use ($colorDoor, $colors, $colorMall): void {
            (new NameListController($colorDoor->app, new NameListStore($colors, new CatalogLinks($colors)), $colorMall))
                ->remove(MallDoor::SLUG, 'colors', MallDoor::ACCOUNT);
        });
        self::assertStringContainsString('the books refused', $page);

        $hidden = new MallRows(false);
        $hiddenDoor = MallDoor::open($this, new Request('GET', '/' . MallDoor::SLUG . '/colors', [], []));
        try {
            (new NameListController($hiddenDoor->app, new NameListStore($hidden, new CatalogLinks($hidden)), $this->mall($hidden, $hiddenDoor->pdo)))
                ->show(MallDoor::SLUG, 'colors');
            self::fail('a disabled color list was shown');
        } catch (ResponseSent $sent) {
            self::assertSame('not found', $sent->getMessage());
        }
    }

    public function testACashierCannotOpenReportsAndAVendorWithoutABoothIsRefused(): void
    {
        $cashier = MallDoor::open($this, new Request('GET', '/' . MallDoor::SLUG . '/pos', [], []));
        $cashier->session->set('actor_role', 'cashier');
        $sheets = new ReportSheetsController($cashier->app, $cashier->actor, new LedgerReport($cashier->statements), new ReportMonth());
        $reports = new ReportsController(
            $cashier->app,
            $cashier->actor,
            new SalesTaxSheet($cashier->statements),
            new SalesTaxFiling(),
            new LedgerReport($cashier->statements),
            new ReportMonth(),
            new SavedReport($cashier->statements, new ReportCatalog()),
        );
        $csv = new ReportCsvController(
            $cashier->app,
            $cashier->actor,
            new SalesTaxSheet($cashier->statements),
            new LedgerReport($cashier->statements),
            new ReportMonth(),
        );
        $this->refused(static function () use ($sheets): void {
            $sheets->rent(MallDoor::SLUG);
        });
        $this->refused(static function () use ($reports): void {
            $reports->show(MallDoor::SLUG);
        });
        $this->refused(static function () use ($csv): void {
            $csv->salesCsv(MallDoor::SLUG);
        });

        $vendor = MallDoor::open($this, new Request('GET', '/' . MallDoor::SLUG . '/vendor', [], []));
        $vendor->session->set('actor_role', 'vendor');
        $this->refused(function () use ($vendor): void {
            (new VendorPreview($vendor->app, $vendor->actor, $this->createStub(\PerfectConsign\Feature\Party\PartyStore::class)))
                ->id(MallDoor::SLUG);
        });
    }

    public function testAnEmptySalesRangeAndANamedSellerListRender(): void
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        $sales = $this->capture(static function () use ($view): void {
            $view->render('Feature/Sales/sales', [
                'slug' => 'demo',
                'range' => 'today',
                'label' => 'Today',
                'rows' => [],
            ]);
        });
        self::assertStringContainsString('No sales in this range.', $sales);

        $register = $this->capture(static function () use ($view): void {
            $view->render('Feature/Pos/pos', [
                'slug' => 'demo',
                'displayName' => 'Demo Mall',
                'csrf' => 'token',
                'activeRegister' => 'Front',
                'chooseRegister' => false,
                'tenders' => [],
                'deposits' => 0,
                'payments' => [],
                'remaining' => 0,
                'salesAvailable' => 0,
                'completed' => null,
                'change' => null,
                'openInstant' => false,
                'openGift' => false,
                'giftError' => '',
                'giftFormNumber' => '',
                'giftFormAmount' => '',
                'giftFormTender' => 'cash',
                'giftFormCheck' => '',
                'giftCustom' => false,
                'giftFormKind' => 'gift_certificate',
                'openPayment' => false,
                'payError' => '',
                'payCash' => '',
                'payCard' => '',
                'payCheck' => '',
                'payCheckNumber' => '',
                'payVendor' => '',
                'payGift' => '',
                'payGiftNumber' => '',
                'payCredit' => '',
                'payCreditNumber' => '',
                'attributeLists' => [],
                'giftNumber' => '',
                'giftBalance' => null,
                'notice' => null,
                'error' => null,
                'sellersJsonUrl' => '/demo/inventory/vendors.json',
                'cart' => [
                    'ticket_id' => 'ticket-1',
                    'lines' => [],
                    'merchandise' => 0,
                    'discount' => 0,
                    'discount_reason' => '',
                    'tax' => 0,
                    'tax_bps' => 0,
                    'waiver' => '',
                    'due' => 0,
                    'cash_round' => 0,
                    'cash_due' => 0,
                    'rounding' => '',
                    'buyer_id' => '',
                    'buyer_name' => '',
                    'parked' => [],
                ],
            ]);
        });
        self::assertStringContainsString('/demo/inventory/vendors.json', $register);
    }

    public function testACustomerWithoutACardAndADeclinedWaitUseAnEmptyPlace(): void
    {
        $door = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/customers/' . MallDoor::PARTY, [], [
            'csrf' => 'csrf-token',
            'customer_name' => 'Ada',
            'customer_phone' => '',
            'customer_email' => 'ada@example.test',
        ]));
        $missing = new RoutedStatements($this->createStub(PDO::class));
        $html = $this->capture(function () use ($door, $missing): void {
            (new CustomerController(
                $door->app,
                new CustomerDirectory($missing),
                new CustomerStore($missing, new PartyIdentity($missing)),
                new SellerPaths($door->mall),
            ))->save(MallDoor::SLUG, MallDoor::PARTY);
        });
        self::assertStringContainsString('ada@example.test', $html);

        $saved = new RoutedStatements($this->createStub(PDO::class));
        $saved->boundRoutes[] = ['match' => 'party_roles', 'rows' => [['present' => 1]]];
        $saved->boundRoutes[] = ['match' => 'FROM persons', 'rows' => [['party_id' => MallDoor::PARTY]]];
        (new CustomerStore($saved, new PartyIdentity($saved)))->save(MallDoor::PARTY, 'Ada Lane', '', 'ada@example.test');

        $waiting = new RoutedStatements($this->createStub(PDO::class));
        $waiting->boundRoutes[] = ['match' => 'SELECT 1 FROM wait_list', 'rows' => [['present' => 1]]];
        $waiting->boundRoutes[] = ['match' => 'entry.party_id AS id', 'rows' => [[
            'id' => MallDoor::VENDOR,
            'first_name' => 'Ada',
            'last_name' => 'Lane',
            'status' => 'declined',
            'email' => 'ada@mall.test',
            'phone' => '5555550100',
            'width_ft' => null,
            'depth_ft' => null,
            'notes' => null,
            'waiting_since' => '2026-01-01',
            'waited_days' => 1,
        ]]];
        $store = new WaitListStore($waiting, new WaitListFields(), $this->createStub(LoggerInterface::class), new PartyIdentity($waiting));
        $store->save(MallDoor::VENDOR, 'Ada', 'Lane', 'ada@mall.test', '5555550100', '', '', 'waiting', '');
        $listed = $store->entries('', 'declined');
        self::assertSame('Declined', $listed[0]['status_label']);
    }

    public function testAZeroLayawayDepositAndAPeriodThatStaysClosedAreRefused(): void
    {
        $book = new LayawayBook(new RoutedStatements($this->createStub(PDO::class)), $this->createStub(LedgerStore::class));
        $deposit = new ReflectionMethod(LayawayBook::class, 'postDeposit');
        try {
            $deposit->invoke($book, 'session', '2026-01-01', 'ticket', 100, 'cash', 0, null);
            self::fail('a zero deposit was posted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('a layaway deposit is the tender and the deposit', $error->getMessage());
        }

        $pdo = $this->createStub(PDO::class);
        $pdo->method('inTransaction')->willReturn(false);
        $pdo->method('prepare')->willReturnCallback(function (string $sql): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('execute')->willReturn(true);
            $statement->method('fetchColumn')->willReturnCallback(static function () use ($sql): string {
                return str_contains($sql, 'INSERT INTO accounting_periods') ? '' : '';
            });

            return $statement;
        });
        try {
            (new LedgerPeriods($pdo))->idFor('book-1', '2026-06-15');
            self::fail('a day that stayed closed was opened');
        } catch (DatabaseRefusal $error) {
            self::assertSame('That day could not be opened.', $error->getMessage());
        }
    }

    public function testAVoidShortOfPayableIsReclassedAndABrokenReceiptIsRethrown(): void
    {
        /** @var LedgerStore&Stub $ledger */
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('roleAccount')->willReturn(MallDoor::ACCOUNT);
        $ledger->method('post')->willReturn(MallDoor::JOURNAL);
        $ledger->method('journal')->willReturn([
            'source_type' => 'sale',
            'is_reversal' => false,
            'source_reference' => 'sale:1',
            'posting_key' => 'ticket-key',
            'journal_no' => 4,
            'posting_date' => '2026-11-12',
            'description' => 'Sale',
        ]);
        $ledger->method('reversed')->willReturn(false);
        $ledger->method('lines')->willReturn([[
            'account_id' => MallDoor::ACCOUNT,
            'credit_minor' => 100,
            'debit_minor' => 0,
            'subledger_type' => 'vendor',
            'subledger_ref' => MallDoor::VENDOR,
        ]]);
        $statements = new RoutedStatements($this->createStub(PDO::class));
        $statements->boundRoutes[] = ['match' => 'AS total', 'rows' => [['total' => 0]]];
        $reversal = new SaleReversal(
            $ledger,
            $statements,
            new SaleCeiling($statements, new Actor($this->createStub(\PerfectApp\Session\Session::class)), new AuditLog($statements), new Request('GET', '/', [], [])),
            new VendorClawback($ledger, $statements),
        );
        self::assertSame(MallDoor::JOURNAL, $reversal->voidSale(MallDoor::DRAWER, '2026-11-12', MallDoor::JOURNAL));

        $broken = $this->createStub(LedgerStore::class);
        $broken->method('journal')->willThrowException(new DatabaseRefusal('the receipt could not be read'));
        $door = MallDoor::open($this, new Request('GET', '/' . MallDoor::SLUG . '/pos/receipt/' . MallDoor::JOURNAL, [], []));
        $poster = new SalePoster(
            $broken,
            new SaleReceipt($broken, $statements),
            new ReceiptChange(),
            new SaleTicketAttach($statements),
            new SaleReversal(
                $broken,
                $statements,
                new SaleCeiling($statements, $door->actor, new AuditLog($statements), new Request('GET', '/', [], [])),
                new VendorClawback($broken, $statements),
            ),
        );
        $view = new ReceiptView(
            $door->app,
            $poster,
            new JournalCash($door->pdo),
            new ReceiptChange(),
            $this->createStub(\PerfectConsign\Feature\Party\PartyStore::class),
        );
        $this->expectException(DatabaseRefusal::class);
        $this->expectExceptionMessage('the receipt could not be read');
        $view->show(MallDoor::SLUG, MallDoor::JOURNAL, null);
    }

    private function refused(callable $run): void
    {
        try {
            $run();
            self::fail('a refused page was shown');
        } catch (ResponseSent $sent) {
            self::assertSame('not found', $sent->getMessage());
        }
    }

    private function mall(Statements $statements, PDO $pdo): MallSettings
    {
        return new MallSettings(
            $statements,
            new CompanyStore($statements, new DatabaseRole($pdo), $pdo, new CompanyLines()),
            new MallDay($statements),
        );
    }

    private function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $names = scandir($path);
        if (is_array($names)) {
            foreach ($names as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $this->removeTree($path . DIRECTORY_SEPARATOR . $name);
            }
        }
        rmdir($path);
    }
}

final class MallRows extends Statements
{
    public function __construct(private readonly bool $catalog)
    {
        parent::__construct(new PDO('sqlite::memory:'));
    }

    public function affected(string $sql, array $params): int
    {
        unset($sql, $params);

        throw new DatabaseRefusal('the books refused');
    }

    public function rows(string $sql): array
    {
        if (str_contains($sql, 'booth_width_ft')) {
            return [[
                'time_zone' => 'America/Los_Angeles',
                'booth_width_ft' => 10,
                'booth_depth_ft' => 10,
                'floor_width_ft' => 10,
                'floor_depth_ft' => 10,
                'commission_bps' => 0,
                'floor_days' => 30,
                'cash_round_minor' => 0,
                'tax_bps' => 0,
                'use_color' => $this->catalog,
                'use_category' => false,
                'use_size' => false,
                'use_condition' => false,
                'use_department' => false,
                'use_brand' => $this->catalog,
                'label_stock' => 'avery_8160',
                'booth_rentals_enabled' => false,
                'rent_cycle_day' => 1,
                'consignment_enabled' => true,
                'store_stock_enabled' => false,
                'receipt_footer' => '',
                'operating_mode' => 'both',
            ]];
        }

        return [];
    }

    public function bound(string $sql, array $params): array
    {
        unset($sql, $params);

        return [];
    }
}
