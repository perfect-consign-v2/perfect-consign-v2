<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Coverage;

use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Bootstrap\EmailSignIn;
use PerfectConsign\Bootstrap\FrontDoor;
use PerfectConsign\Bootstrap\LoginSession;
use PerfectConsign\Bootstrap\OwnerGate;
use PerfectConsign\Bootstrap\SignInDirectory;
use PerfectConsign\Bootstrap\SignInFailureRetention;
use PerfectConsign\Bootstrap\SignInUnverified;
use PerfectConsign\Bootstrap\StaffSignIn;
use PerfectConsign\Bootstrap\TenantLookup;
use PerfectConsign\Feature\Catalog\NameList;
use PerfectConsign\Feature\Catalog\NameListStore;
use PerfectConsign\Feature\Inventory\BarcodePng;
use PerfectConsign\Feature\Inventory\Code128;
use PerfectConsign\Feature\Inventory\IntakeCatalog;
use PerfectConsign\Feature\Inventory\InventoryStore;
use PerfectConsign\Feature\Inventory\ItemForm;
use PerfectConsign\Feature\Inventory\ItemQueries;
use PerfectConsign\Feature\Inventory\LabelCatalog;
use PerfectConsign\Feature\Inventory\LabelFace;
use PerfectConsign\Feature\Inventory\LabelRequest;
use PerfectConsign\Feature\Inventory\LabelSheet;
use PerfectConsign\Feature\Inventory\StickerFromRow;
use PerfectConsign\Feature\Inventory\StoreTake;
use PerfectConsign\Feature\Mall\MallPayoutSettings;
use PerfectConsign\Feature\Mall\ReceiptMailSettings;
use PerfectConsign\Feature\Party\PartyIdentity;
use PerfectConsign\Feature\Party\PartyStore;
use PerfectConsign\Feature\Password\LoginAccounts;
use PerfectConsign\Feature\Password\PasswordChoice;
use PerfectConsign\Feature\Password\PasswordRecovery;
use PerfectConsign\Feature\Password\RecoveryAddress;
use PerfectConsign\Feature\Password\ResetLetter;
use PerfectConsign\Feature\Password\ResetRequests;
use PerfectConsign\Feature\Password\ResetTokens;
use PerfectConsign\Feature\Payout\OccupiedPack;
use PerfectConsign\Feature\Payout\PayoutRunStore;
use PerfectConsign\Feature\Payout\PayoutSettler;
use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Feature\Payout\PayoutWindow;
use PerfectConsign\Feature\Rent\LateFee;
use PerfectConsign\Feature\Rent\RentPayment;
use PerfectConsign\Feature\Rent\RentPosting;
use PerfectConsign\Feature\Rent\RentReceipt;
use PerfectConsign\Feature\Rent\RentSlip;
use PerfectConsign\Feature\Rent\RentStore;
use PerfectConsign\Feature\Report\LedgerReport;
use PerfectConsign\Feature\Report\ReportCatalog;
use PerfectConsign\Feature\Report\ReportCsvController;
use PerfectConsign\Feature\Report\ReportMonth;
use PerfectConsign\Feature\Report\ReportSheetsController;
use PerfectConsign\Feature\Report\ReportsController;
use PerfectConsign\Feature\Report\SalesTaxFiling;
use PerfectConsign\Feature\Report\SalesTaxSheet;
use PerfectConsign\Feature\Report\SavedReport;
use PerfectConsign\Feature\Root\RootController;
use PerfectConsign\Feature\Signup\PlatformSettings;
use PerfectConsign\Feature\Staff\MallOwners;
use PerfectConsign\Feature\Staff\StaffStore;
use PerfectConsign\Feature\Tax\TaxController;
use PerfectConsign\Feature\Tax\TaxStore;
use PerfectConsign\Feature\Vendor\VendorAccount;
use PerfectConsign\Feature\Vendor\VendorCheck;
use PerfectConsign\Feature\Vendor\VendorEntryController;
use PerfectConsign\Feature\Vendor\VendorHomeController;
use PerfectConsign\Feature\Vendor\VendorEntry;
use PerfectConsign\Feature\Vendor\VendorPortal;
use PerfectConsign\Feature\Vendor\VendorPreview;
use PerfectConsign\Feature\Vendor\VendorSales;
use PerfectConsign\Feature\Vendor\VendorStickers;
use PerfectConsign\Feature\WaitList\WaitListController;
use PerfectConsign\Feature\WaitList\WaitListFields;
use PerfectConsign\Feature\WaitList\WaitListStore;
use PerfectConsign\Ledger\CashRound;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalCash;
use PerfectConsign\Ledger\LedgerPeriods;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Schema\MigrationLock;
use PerfectConsign\Schema\MigrationRecord;
use PerfectConsign\Schema\SchemaCatalog;
use PerfectConsign\Schema\TenantBaselineMismatch;
use PerfectConsign\Schema\TenantMigrator;
use PerfectConsign\Shared\Database\CatalogLinks;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Http\AssetServer;
use PerfectConsign\Shared\Http\Csv;
use PerfectConsign\Shared\Http\EnvFile;
use PerfectConsign\Shared\Http\ErrorHandling;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Jobs\DigestJob;
use PerfectConsign\Shared\Jobs\JobDispatch;
use PerfectConsign\Shared\Jobs\JobQueue;
use PerfectConsign\Shared\Jobs\JobRunner;
use PerfectConsign\Shared\Jobs\JobSchedule;
use PerfectConsign\Shared\Jobs\LateFeeJob;
use PerfectConsign\Shared\Jobs\MarkdownJob;
use PerfectConsign\Shared\Jobs\OutboundMailJob;
use PerfectConsign\Shared\Jobs\ReconcileJob;
use PerfectConsign\Shared\Jobs\SaleNoticeJob;
use PerfectConsign\Shared\Jobs\VendorLetterJob;
use PerfectConsign\Shared\Jobs\WorkerRuns;
use PerfectConsign\Shared\Mail\GmailSender;
use PerfectConsign\Shared\Mail\LetterMailbox;
use PerfectConsign\Shared\Mail\MailAttempt;
use PerfectConsign\Shared\Mail\MailBounce;
use PerfectConsign\Shared\Mail\MailDeliveryFailed;
use PerfectConsign\Shared\Mail\MailKeyFile;
use PerfectConsign\Shared\Mail\MailOutbox;
use PerfectConsign\Shared\Mail\MailProgress;
use PerfectConsign\Shared\Mail\MailboxSeal;
use PerfectConsign\Shared\Mail\OutboundMail;
use PerfectConsign\Shared\Mail\PlatformMailbox;
use PerfectConsign\Shared\Mail\PlatformOperatorNotice;
use PerfectConsign\Shared\Mail\SealedSecret;
use PerfectConsign\Shared\Mail\SmtpClient;
use PerfectConsign\Shared\Tenant\TenantRecord;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

final class ListedLineCoverageTest extends TestCase
{
    use CapturesPage;

    private const VENDOR = '01900000-0000-7000-8000-0000000000c1';

    private const ACCOUNT = '01900000-0000-7000-8000-0000000000aa';

    private const SCHEMA = 'tenant_abababababababababababababababab';

    protected function tearDown(): void
    {
        ReceiptMailSettings::useTransport('smtp.gmail.com', 587);
        ErrorHandling::$shutdown = null;
    }

    public function testErrorShutdownSeesNoErrorAndAWarning(): void
    {
        $logger = $this->createStub(LoggerInterface::class);
        ErrorHandling::register($logger);
        $shutdown = ErrorHandling::$shutdown;
        self::assertNotNull($shutdown);
        $shutdown();
        $this->swallow(static function (): void {
            trigger_error('listed warning', E_USER_WARNING);
        });
        $shutdown();
        restore_error_handler();
    }

    public function testAReportedFatalClearsThePage(): void
    {
        $this->expectOutputString('The page could not be shown.');
        $logger = $this->createStub(LoggerInterface::class);
        ob_start();
        ErrorHandling::reportLastError([
            'type' => E_ERROR,
            'message' => 'boom',
            'file' => 'index.php',
            'line' => 1,
        ], $logger, null, ob_get_level() - 1);
    }

    public function testErrorHandlerThrowsAndIgnoresANestedWarning(): void
    {
        $nested = false;
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(static function () use (&$nested): void {
            if ($nested) {
                return;
            }
            $nested = true;
            trigger_error('nested', E_USER_WARNING);
        });
        $reporting = error_reporting();
        $display = ini_get('display_errors');
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        set_error_handler(static fn (): bool => true);
        ErrorHandling::register($logger);
        try {
            try {
                trigger_error('listed', E_USER_WARNING);
                self::fail('the warning was not thrown');
            } catch (\ErrorException $error) {
                self::assertSame('listed', $error->getMessage());
            }
            error_reporting(0);
            trigger_error('quiet', E_USER_WARNING);
        } finally {
            error_reporting($reporting);
            ini_set('display_errors', $display === false ? '0' : $display);
            restore_error_handler();
            restore_error_handler();
        }
    }

    public function testAFatalShutdownClearsTheBuffer(): void
    {
        $root = dirname(__DIR__, 3);
        $script = tempnam(sys_get_temp_dir(), 'pc2fatal');
        self::assertNotFalse($script);
        $written = file_put_contents($script, $this->fatalScript());
        self::assertNotFalse($written);
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $script, $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertNotFalse($process);
        $body = is_resource($pipes[1]) ? stream_get_contents($pipes[1]) : '';
        if (is_resource($pipes[1])) {
            fclose($pipes[1]);
        }
        if (is_resource($pipes[2])) {
            fclose($pipes[2]);
        }
        proc_close($process);
        unlink($script);
        self::assertIsString($body);
        self::assertStringContainsString('The page could not be shown.', $body);
    }

    public function testCashRoundRejectsAnUnknownStep(): void
    {
        self::assertFalse(CashRound::allowed(2));
        $this->expectException(\InvalidArgumentException::class);
        CashRound::parse('2');
    }

    public function testReportCatalogAndMonthGuards(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ReportCatalog())->accept('Weekly', 'payroll', 'none');
    }

    public function testARentReceiptExplainsTenderFailures(): void
    {
        $posting = $this->posting(false, false);
        $tender = ['cash' => '0', 'card' => '1.00', 'checks' => '0', 'tendered' => '', 'check' => '44'];
        try {
            $posting->tenders(100, $tender);
            self::fail('a card receipt kept a check number');
        } catch (DatabaseRefusal $error) {
            self::assertSame('a cash or card rent receipt has no check number', $error->getMessage());
        }
        try {
            $posting->tenders(100, ['cash' => '1.00', 'card' => '0', 'checks' => '0', 'tendered' => '0.50', 'check' => '']);
            self::fail('short cash was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('the cash tendered does not cover the cash amount', $error->getMessage());
        }
        try {
            $posting->tenders(0, ['cash' => '0', 'card' => '0', 'checks' => '0', 'tendered' => '', 'check' => '']);
            self::fail('an empty tender was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('a rent receipt needs a tender', $error->getMessage());
        }
    }

    public function testPrepaidRentReceiptStopsWhenThePackOrTheShareIsWrong(): void
    {
        $vendor = self::VENDOR;
        try {
            $this->posting(true, false)->postReceipt($vendor, '2026-06-15', '', 100, $this->blankTender(), []);
            self::fail('a missing pack was posted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('a rent receipt cannot exceed the open rent', $error->getMessage());
        }
        try {
            $this->posting(true, true)->postReceipt('', '2026-06-15', '', 100, $this->blankTender(), []);
            self::fail('an empty share was posted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('a rent receipt cannot exceed the open rent', $error->getMessage());
        }
    }

    public function testTakeRefusesAMissingFigureAnOpenRegisterAndAnEmptyTender(): void
    {
        $receipt = $this->receipt(false);
        $method = new ReflectionMethod(RentReceipt::class, 'take');
        $tender = $this->blankTender();
        try {
            $method->invoke($receipt, '', '', '2026-06-15', 'session', '1.00', $tender);
            self::fail('a missing figure was collected');
        } catch (DatabaseRefusal $error) {
            self::assertSame('a rent receipt cannot exceed the open rent', $error->getMessage());
        }
        $open = $this->receipt(true);
        try {
            $method->invoke($open, self::VENDOR, self::VENDOR, '2026-06-15', '', '1.00', $tender);
            self::fail('a closed register collected rent');
        } catch (DatabaseRefusal $error) {
            self::assertSame('Open a register to collect', $error->getMessage());
        }
        try {
            $tender['way'] = 'cash';
            $tender['tendered'] = '0';
            $method->invoke($open, self::VENDOR, self::VENDOR, '2026-06-15', 'session', '0', $tender);
            self::fail('a zero tender was collected');
        } catch (DatabaseRefusal $error) {
            self::assertSame('a rent receipt needs a tender', $error->getMessage());
        }
    }

    public function testALateFeeWithOneLineCannotBeWaived(): void
    {
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('journal')->willReturn([
            'source_type' => 'late_fee',
            'is_reversal' => false,
            'posting_date' => '2026-01-01',
            'source_reference' => 'booth:2026-01:1',
        ]);
        $ledger->method('reversed')->willReturn(false);
        $ledger->method('lines')->willReturn([[
            'account_id' => self::ACCOUNT,
            'credit_minor' => 100,
            'debit_minor' => 0,
            'subledger_type' => null,
            'subledger_ref' => null,
        ]]);
        $fees = new LateFee($ledger, new Statements($this->createStub(PDO::class)));
        $this->expectException(DatabaseRefusal::class);
        $this->expectExceptionMessage('Choose a late fee.');
        $fees->waive(self::VENDOR, '2026-06-15');
    }

    public function testRentStoreRefusesAMissingRateAndABadMonthList(): void
    {
        $store = new RentStore(
            new FlexibleStatements(static function (string $sql): array {
                if (str_contains($sql, 'stall_rent_charge_suffix')) {
                    return [['month' => '2026-01-02']];
                }
                if (str_contains($sql, 'amount_minor') || str_contains($sql, 'vendor_name')) {
                    return [];
                }
                if (str_contains($sql, 'starts_on')) {
                    return [['starts_on' => '2026-01-01']];
                }

                return [];
            }, static function (string $sql): array {
                if (str_contains($sql, 'rent_cycle_day')) {
                    return [['rent_cycle_day' => 1]];
                }

                return [];
            }),
            $this->mallSettings(),
            new \PerfectConsign\Feature\Rent\RentLines(),
            $this->createStub(LedgerStore::class),
        );
        self::assertNull($store->rate(self::VENDOR, '2026-06-15'));
        try {
            $store->chargeAmount(self::VENDOR, '2026-06-15');
            self::fail('a missing rate was charged');
        } catch (DatabaseRefusal $error) {
            self::assertSame('a rent charge requires the booth rate from the assignment', $error->getMessage());
        }
        try {
            $store->unchargedMonths('yesterday', '2026-06');
            self::fail('a bad day was listed');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('a rent day is YYYY-MM-DD', $error->getMessage());
        }
        try {
            $store->unchargedMonths('2026-06-15', 'June');
            self::fail('a bad month was listed');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('a rent month is YYYY-MM', $error->getMessage());
        }
        self::assertSame([], $store->uncharged('2026-06-15', '2026-05'));
    }

    public function testReportSheetsRenderRentStoredAndAClampedRange(): void
    {
        $door = MallDoor::open($this, new Request('GET', '/gapmall/reports/rent', [], []));
        $sheets = $this->sheets($door);
        $html = $this->capture(static function () use ($sheets): void {
            $sheets->rent(MallDoor::SLUG);
        });
        self::assertStringContainsString('/reports/rent.csv', $html);
        $stored = MallDoor::open($this, new Request('GET', '/gapmall/reports/stored-value', [], []));
        $html = $this->capture(function () use ($stored): void {
            $this->sheets($stored)->storedValue(MallDoor::SLUG);
        });
        self::assertStringContainsString('/reports/stored-value.csv', $html);
        $ranged = MallDoor::open($this, new Request('GET', '/gapmall/reports/donated', [
            'from' => '2026-02-02',
            'to' => '2026-01-01',
        ], []));
        $this->capture(function () use ($ranged): void {
            $this->sheets($ranged)->donated(MallDoor::SLUG);
        });
        $denied = MallDoor::open($this, new Request('GET', '/gapmall/reports/rent', [], []));
        $denied->session->set('actor_role', 'vendor');
        $this->capture(function () use ($denied): void {
            $this->sheets($denied)->rent(MallDoor::SLUG);
        });
        $reports = MallDoor::open($this, new Request('GET', '/gapmall/reports', [], []));
        $reports->session->set('actor_role', 'vendor');
        $this->capture(function () use ($reports): void {
            $this->reports($reports)->show(MallDoor::SLUG);
        });
        $csv = MallDoor::open($this, new Request('GET', '/gapmall/reports/sales.csv', [], []));
        $csv->session->set('actor_role', 'vendor');
        $this->capture(function () use ($csv): void {
            $this->csvController($csv)->salesCsv(MallDoor::SLUG);
        });
    }

    public function testTemplatesRenderTheRemainingBranches(): void
    {
        $view = new View(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src');
        $slip = $this->capture(static function () use ($view): void {
            $view->render('Feature/Rent/rent-slip', [
                'slug' => 'gapmall',
                'displayName' => 'Gap',
                'change' => null,
                'slip' => [
                    'journal_id' => self::VENDOR,
                    'number' => 4,
                    'posted_on' => '2026-06-15',
                    'vendor' => 'Ada',
                    'booth' => 'A1',
                    'amount' => 100,
                    'cash' => 100,
                    'check_number' => '44',
                    'tenders' => [],
                ],
            ]);
        });
        self::assertStringContainsString('Check number', $slip);
        $enter = $this->capture(static function () use ($view): void {
            $view->render('Feature/Vendor/enter', [
                'slug' => 'gapmall',
                'csrf' => 'csrf-token',
                'error' => 'That link expired.',
                'notice' => null,
            ]);
        });
        self::assertStringContainsString('That link expired.', $enter);
        $home = $this->capture(static function () use ($view): void {
            $view->render('Feature/Vendor/consignor', [
                'slug' => 'gapmall',
                'csrf' => 'csrf-token',
                'page' => [
                    'items' => [],
                    'sales' => [['posting_date' => '2026-06-15']],
                    'payable' => 0,
                    'expiring' => [['description' => 'Coat']],
                    'agreement' => ['payout_mode' => 'pay'],
                ],
            ]);
        });
        self::assertStringContainsString('2026-06-15', $home);
        self::assertStringContainsString('Coat', $home);
        $sales = $this->capture(static function () use ($view): void {
            $view->render('Feature/Vendor/sales', [
                'slug' => 'gapmall',
                'span' => ['from' => '2026-06-01', 'to' => '2026-06-15', 'label' => 'This month'],
                'total' => 1,
                'rows' => [[
                    'date' => '2026-06-15',
                    'code' => '100',
                    'description' => 'Coat',
                    'price' => 100,
                    'discount' => 0,
                    'sold' => 100,
                    'take' => 40,
                    'share' => 60,
                    'item_id' => self::VENDOR,
                    'has_photo' => true,
                ]],
            ]);
        });
        self::assertStringContainsString('/inventory/', $sales);
        $items = $this->capture(static function () use ($view): void {
            $view->render('Feature/Vendor/items', [
                'slug' => 'gapmall',
                'csrf' => 'csrf-token',
                'query' => '',
                'stock' => '',
                'available' => 1,
                'unsold' => 0,
                'unprinted' => 0,
                'total' => 1,
                'labelStock' => 'avery_8160',
                'stocks' => [],
                'attributeLists' => [new NameList('color', 'colors', 'color_id', 'use_color', 'colors', 'color', 'Colors')],
                'items' => [[
                    'id' => self::VENDOR,
                    'code' => '100',
                    'barcode' => '100',
                    'description' => 'Coat',
                    'brand_name' => '',
                    'color_name' => 'Red',
                    'intake_on' => '2026-06-01',
                    'expires_on' => '',
                    'take_label' => '40',
                    'overridden' => false,
                    'quantity' => 1,
                    'price_minor' => 100,
                ]],
            ]);
        });
        self::assertStringContainsString('Red', $items);
    }

    public function testVendorPortalPreviewStickersAndAClearedCheck(): void
    {
        $portal = new VendorPortal($this->execPdo(false), new DatabaseRole($this->execPdo(true)), new Statements($this->createStub(PDO::class)));
        try {
            $portal->page($this->tenant('public'), self::VENDOR);
            self::fail('a public schema was used');
        } catch (RuntimeException $error) {
            self::assertSame('the mall schema is not usable', $error->getMessage());
        }
        try {
            $portal->page($this->tenant(self::SCHEMA), self::VENDOR);
            self::fail('a failed search path was used');
        } catch (RuntimeException $error) {
            self::assertSame('the mall schema could not be used', $error->getMessage());
        }

        $cashier = MallDoor::open($this, new Request('GET', '/gapmall/vendor', ['as' => ''], []));
        $cashier->session->set('actor_role', 'cashier');
        $cashier->session->set('actor_vendor', '');
        $this->capture(function () use ($cashier): void {
            $this->preview($cashier)->id(MallDoor::SLUG);
        });
        $owner = MallDoor::open($this, new Request('GET', '/gapmall/vendor', ['as' => self::VENDOR], []));
        $owner->session->set('actor_vendor', '');
        $this->capture(function () use ($owner): void {
            $this->preview($owner)->id(MallDoor::SLUG);
        });

        $labels = MallDoor::open($this, new Request('POST', '/gapmall/vendor/labels', [], ['csrf' => 'csrf-token']));
        $this->capture(function () use ($labels): void {
            $this->stickers($labels)->printOwn(MallDoor::SLUG, '');
        });

        $check = MallDoor::open($this, new Request('GET', '/gapmall/vendor/checks/' . MallDoor::RUN, [], []));
        $check->session->set('actor_vendor', self::VENDOR);
        $html = $this->capture(function () use ($check): void {
            $this->home($check)->check(MallDoor::SLUG, MallDoor::RUN);
        });
        self::assertStringContainsString('Vendor memo', $html);
    }

    public function testConsignorLogoutAndAStaleSession(): void
    {
        $door = MallDoor::open($this, new Request('POST', '/gapmall/consignor/logout', [], ['csrf' => 'csrf-token']));
        $this->capture(function () use ($door): void {
            $this->entryController($door)->logout(MallDoor::SLUG);
        });
        $stale = MallDoor::open($this, new Request('GET', '/gapmall/consignor', [], []));
        $stale->session->set('consignor:' . MallDoor::SLUG, self::VENDOR);
        $this->capture(function () use ($stale): void {
            $this->entryController($stale)->home(MallDoor::SLUG);
        });
    }

    public function testStaffOwnersAndSignupSettingsRefuseTheRemainingCases(): void
    {
        $owners = new MallOwners($this->ownerPdo(null, 0), new DatabaseRole($this->execPdo(true)), new RecoveryAddress(), new PasswordChoice());
        try {
            $owners->setCanSignIn($this->tenant(self::SCHEMA), 'nope', false);
            self::fail('a bad owner id was suspended');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Choose an owner.', $error->getMessage());
        }
        try {
            $owners->setCanSignIn($this->tenant(self::SCHEMA), self::VENDOR, true);
            self::fail('a missing owner was updated');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Choose an owner.', $error->getMessage());
        }
        try {
            $owners->add($this->tenant(self::SCHEMA), '1bad', 'password1', 'a@b.co');
            self::fail('a bad sign-in name was added');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Use a sign-in name of letters, numbers, and hyphens.', $error->getMessage());
        }
        try {
            $owners->replaceSignIn($this->tenant(self::SCHEMA), 'nope', 'password1', 'a@b.co');
            self::fail('a bad owner id was replaced');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Choose an owner.', $error->getMessage());
        }
        try {
            $owners->remove($this->tenant(self::SCHEMA), 'nope', self::ACCOUNT);
            self::fail('a bad owner id was removed');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Choose an owner.', $error->getMessage());
        }

        $duplicate = new PDOException('duplicate');
        $duplicate->errorInfo = ['23505', '23505', 'duplicate'];
        $dupOwners = new MallOwners($this->ownerPdo($duplicate, 1), new DatabaseRole($this->execPdo(true)), new RecoveryAddress(), new PasswordChoice());
        try {
            $dupOwners->replaceSignIn($this->tenant(self::SCHEMA), self::VENDOR, 'password1', 'a@b.co');
            self::fail('a duplicate email was saved');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('That email is already used on another sign-in.', $error->getMessage());
        }
        $other = new PDOException('blocked');
        $other->errorInfo = ['23503', '23503', 'blocked'];
        $blocked = new MallOwners($this->ownerPdo($other, 1), new DatabaseRole($this->execPdo(true)), new RecoveryAddress(), new PasswordChoice());
        try {
            $blocked->replaceSignIn($this->tenant(self::SCHEMA), self::VENDOR, 'password1', 'a@b.co');
            self::fail('a foreign key failure was saved');
        } catch (DatabaseRefusal $error) {
            self::assertNotSame('', $error->getMessage());
        }
        $remove = new MallOwners($this->ownerPdo(new PDOException('blocked'), 1, 'remove_mall_owner'), new DatabaseRole($this->execPdo(true)), new RecoveryAddress(), new PasswordChoice());
        try {
            $remove->remove($this->tenant(self::SCHEMA), self::VENDOR, self::ACCOUNT);
            self::fail('a blocked remove was saved');
        } catch (DatabaseRefusal $error) {
            self::assertNotSame('', $error->getMessage());
        }

        $door = MallDoor::open($this, new Request('POST', '/gapmall/staff/' . MallDoor::PERSON . '/role', [], ['csrf' => 'csrf-token', 'role' => 'cashier']));
        $staff = new StaffStore($door->statements, new RecoveryAddress(), new PasswordChoice());
        $this->expectException(\InvalidArgumentException::class);
        $staff->changeRole('nope', 'cashier');
    }

    public function testSignupSettingKeepsUnknownTextAndRefusesAMissedWrite(): void
    {
        $read = new ScriptedResult(column: 'yes');
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($read);
        $settings = new PlatformSettings($pdo, $this->createStub(LoggerInterface::class));
        self::assertFalse($settings->storeSignupEnabled());
        $write = new ScriptedResult(count: 0);
        $writing = $this->createStub(PDO::class);
        $writing->method('prepare')->willReturn($write);
        $this->expectException(RuntimeException::class);
        (new PlatformSettings($writing, $this->createStub(LoggerInterface::class)))->setStoreSignupEnabled(true);
    }

    public function testTaxPaymentRedirectsWhenTheJournalPosts(): void
    {
        $door = MallDoor::open($this, new Request('POST', '/gapmall/tax', [], [
            'csrf' => 'csrf-token',
            'source_reference' => 'june',
            'amount' => '1.00',
            'credit' => 'bank',
            'posting_date' => '2026-06-15',
        ]));
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('roleAccount')->willReturn(self::ACCOUNT);
        $ledger->method('post')->willReturn(MallDoor::JOURNAL);
        $controller = new TaxController($door->app, $ledger, new TaxStore($door->pdo));
        $html = $this->capture(static function () use ($controller): void {
            $controller->submit(MallDoor::SLUG);
        });
        self::assertSame('', $html);
    }

    public function testWaitListNullPlaceZeroUpdateAndARemovalRefusal(): void
    {
        $seen = 0;
        $statements = new FlexibleStatements(
            static function (string $sql) use (&$seen): array {
                if (str_contains($sql, 'first_name')) {
                    $seen++;

                    return [self::waitRow($seen === 1 ? 'contacted' : 'placed')];
                }
                if (str_contains($sql, 'FROM wait_list')) {
                    return [['present' => 1]];
                }

                return [];
            },
            static fn (): array => [],
            static function (string $sql): int {
                return str_contains($sql, 'UPDATE wait_list') ? 0 : 1;
            },
        );
        $store = new WaitListStore($statements, new WaitListFields(), $this->createStub(LoggerInterface::class), new PartyIdentity($statements));
        $listed = $store->entries('', 'contacted');
        self::assertSame('Contacted', $listed[0]['status_label']);
        $placed = $store->entries('', 'placed');
        self::assertSame('Placed', $placed[0]['status_label']);
        try {
            $store->save(self::VENDOR, 'Ada', 'Lane', 'ada@mall.test', '5555550100', '', '', 'waiting', '');
            self::fail('a missing wait-list row was saved');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('That person is not on the wait list.', $error->getMessage());
        }

        $door = MallDoor::open($this, new Request('POST', '/gapmall/staff', [], ['csrf' => 'csrf-token']));
        $door->statements->affectedRefuses = true;
        $html = $this->capture(function () use ($door): void {
            (new WaitListController($door->app, new WaitListStore($door->statements, new WaitListFields(), $this->createStub(LoggerInterface::class))))
                ->remove(MallDoor::SLUG, MallDoor::PERSON);
        });
        self::assertStringContainsString('Wait list', $html);
    }

    public function testRootSignInShowsAnUnverifiedMailboxAndAMissingSlug(): void
    {
        $hash = password_hash('password1', PASSWORD_DEFAULT);
        $request = new Request('POST', '/', [], ['csrf' => 'csrf-token', 'email' => 'ada@mall.test', 'password' => 'password1']);
        $door = MallDoor::open($this, $request);
        $controller = $this->root($door, $hash, false, $door->session);
        $html = $this->capture(static function () use ($controller): void {
            $controller->submit();
        });
        self::assertStringContainsString('Confirm your email before signing in.', $html);

        $empty = $this->createStub(\PerfectApp\Session\Session::class);
        $empty->method('get')->willReturn(null);
        $signed = $this->root($door, $hash, true, $empty);
        $html = $this->capture(static function () use ($signed): void {
            $signed->submit();
        });
        self::assertStringContainsString('That email or password is wrong.', $html);
    }

    public function testLedgerRefusesAMissingBook(): void
    {
        $store = $this->ledger(false, null);
        try {
            $store->post($this->draft());
            self::fail('a missing query posted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('the mall book is missing', $error->getMessage());
        }
        $empty = $this->ledger(true, false);
        try {
            $empty->post($this->draft());
            self::fail('an empty book posted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('the mall book is missing', $error->getMessage());
        }
        try {
            $this->ledger(false, null)->addPeriod('2026-01-01', '2026-12-31');
            self::fail('a missing book opened a period');
        } catch (DatabaseRefusal $error) {
            self::assertSame('the mall book is missing', $error->getMessage());
        }
        try {
            $this->ledger(true, false)->addPeriod('2026-01-01', '2026-12-31');
            self::fail('a non-string book id opened a period');
        } catch (DatabaseRefusal $error) {
            self::assertSame('the mall book is missing', $error->getMessage());
        }
    }

    public function testMigrationLockReleaseRollsBackABrokenProbe(): void
    {
        $false = $this->createMock(PDO::class);
        $false->method('inTransaction')->willReturn(true);
        $false->method('query')->willReturnCallback(static function (string $sql): PDOStatement|false {
            if (str_contains($sql, 'pg_advisory_unlock')) {
                return new ScriptedResult();
            }

            return false;
        });
        $false->expects($this->once())->method('rollBack')->willReturn(true);
        (new MigrationLock($false))->release();

        $thrown = $this->createMock(PDO::class);
        $thrown->method('inTransaction')->willReturn(true);
        $thrown->method('query')->willReturnCallback(static function (string $sql): PDOStatement {
            if (str_contains($sql, 'pg_advisory_unlock')) {
                return new ScriptedResult();
            }

            throw new PDOException('aborted');
        });
        $thrown->expects($this->once())->method('rollBack')->willReturn(true);
        (new MigrationLock($thrown))->release();

        $held = $this->createStub(PDO::class);
        $held->method('query')->willReturn(false);
        $this->expectException(RuntimeException::class);
        (new MigrationLock($held))->hold();
    }

    public function testMigrationRecordRefusesADriftedBaselineAndABadRow(): void
    {
        $catalog = new SchemaCatalog(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'sql');
        $closed = $this->createStub(PDO::class);
        $closed->method('prepare')->willReturn(new ScriptedResult(column: false));
        $closed->method('quote')->willReturn("'x'");
        try {
            (new MigrationRecord($closed, $catalog))->requireBaseline('gapmall', self::SCHEMA);
            self::fail('an open window was stamped');
        } catch (TenantBaselineMismatch $error) {
            self::assertStringContainsString('closed payout window', $error->getMessage());
        }
        $missing = $this->createStub(PDO::class);
        $missing->method('prepare')->willReturn(new ScriptedResult(column: 1));
        $missing->method('query')->willReturn(false);
        $missing->method('quote')->willReturn("'x'");
        try {
            (new MigrationRecord($missing, $catalog))->requireBaseline('gapmall', self::SCHEMA);
            self::fail('a missing object was stamped');
        } catch (TenantBaselineMismatch $error) {
            self::assertStringContainsString('is missing', $error->getMessage());
        }
        $changed = $this->createStub(PDO::class);
        $changed->method('query')->willReturn(new ScriptedResult(rows: [[
            'filename' => '001_tenant.sql',
            'checksum' => str_repeat('a', 64),
        ]]));
        try {
            (new MigrationRecord($changed, $catalog))->stamp(self::SCHEMA, ['001_tenant.sql']);
            self::fail('a changed file was stamped');
        } catch (\PerfectConsign\Schema\MigrationFileChanged $error) {
            self::assertStringContainsString('changed after', $error->getMessage());
        }
        $bad = $this->createStub(PDO::class);
        $bad->method('query')->willReturn(new ScriptedResult(rows: ['nope']));
        $this->expectException(RuntimeException::class);
        (new MigrationRecord($bad, $catalog))->applied(self::SCHEMA);
    }

    public function testSchemaReadAndTenantApplyFailures(): void
    {
        stream_wrapper_register('pc2gap', GapStream::class);
        try {
            $catalog = new SchemaCatalog('pc2gap://gap');
            $this->expectException(RuntimeException::class);
            $this->swallow(static function () use ($catalog): void {
                $catalog->read('999_gap_read.sql');
            });
        } finally {
            stream_wrapper_unregister('pc2gap');
        }
    }

    public function testTenantApplyRollsBackAndRejectsABadName(): void
    {
        $open = false;
        $queries = 0;
        $pdo = $this->createStub(PDO::class);
        $pdo->method('quote')->willReturn("'x'");
        $pdo->method('inTransaction')->willReturnCallback(static function () use (&$open): bool {
            return $open;
        });
        $pdo->method('beginTransaction')->willReturnCallback(static function () use (&$open): bool {
            $open = true;

            return true;
        });
        $pdo->method('rollBack')->willReturnCallback(static function () use (&$open): bool {
            $open = false;

            return true;
        });
        $pdo->method('prepare')->willReturn(new ScriptedResult(column: self::SCHEMA));
        $pdo->method('query')->willReturnCallback(static function () use (&$queries): PDOStatement|false {
            $queries++;
            if ($queries === 1 || $queries === 5) {
                return new ScriptedResult(column: 1);
            }

            return false;
        });
        $pdo->method('exec')->willReturnCallback(static function (string $sql): int {
            if (str_starts_with($sql, 'SET search_path')) {
                return 0;
            }
            throw new RuntimeException('apply failed');
        });
        $migrator = new TenantMigrator(
            $pdo,
            new SchemaCatalog(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'sql'),
            new MigrationRecord($pdo, new SchemaCatalog(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'sql')),
        );
        try {
            $migrator->bringCurrent('gapmall');
            self::fail('a failed file was applied');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('was not applied', $error->getMessage());
        }
        $role = new ReflectionMethod(TenantMigrator::class, 'roleName');
        try {
            $role->invoke($migrator, 'Bad', self::SCHEMA);
            self::fail('a bad slug named a role');
        } catch (RuntimeException $error) {
            self::assertSame('a tenant slug must be a short lowercase name', $error->getMessage());
        }
        try {
            $role->invoke($migrator, 'gapmall', 'public');
            self::fail('a public schema named a role');
        } catch (RuntimeException $error) {
            self::assertSame('schema name is not a tenant schema', $error->getMessage());
        }
    }

    public function testStatementsQueryFailures(): void
    {
        $thrown = $this->createStub(PDO::class);
        $thrown->method('query')->willThrowException(new PDOException('nope'));
        try {
            (new Statements($thrown))->rows('SELECT 1');
            self::fail('a query exception was returned');
        } catch (DatabaseRefusal $error) {
            self::assertNotSame('', $error->getMessage());
        }
        $false = $this->createStub(PDO::class);
        $false->method('query')->willReturn(false);
        $this->expectException(DatabaseRefusal::class);
        (new Statements($false))->rows('SELECT 1');
    }

    public function testAssetsCsvAndEnvFailures(): void
    {
        $missing = $this->capture(static function (): void {
            AssetServer::send('/assets/listed-line-missing.css');
        });
        self::assertStringContainsString('Not found', $missing);

        $file = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . '_listed_unread.txt';
        $written = file_put_contents($file, 'hi');
        self::assertNotFalse($written);
        $denied = false;
        try {
            $ran = shell_exec('icacls ' . escapeshellarg($file) . ' /deny Everyone:(R)');
            $denied = is_string($ran);
            $body = $this->swallow(static function (): string {
                ob_start();
                AssetServer::send('/assets/_listed_unread.txt');
                $html = ob_get_clean();

                return is_string($html) ? $html : '';
            });
            self::assertStringContainsString('could not be shown', $body);
        } finally {
            if ($denied) {
                shell_exec('icacls ' . escapeshellarg($file) . ' /remove:d Everyone');
            }
            if (is_file($file)) {
                unlink($file);
            }
        }

        stream_wrapper_register('pc2gap', GapStream::class);
        try {
            $this->swallow(static function (): void {
                try {
                    Csv::document(['a'], [['a' => '1']], sys_get_temp_dir());
                    self::fail('a directory opened as csv');
                } catch (RuntimeException $error) {
                    self::assertSame('could not open a csv buffer', $error->getMessage());
                }
                try {
                    Csv::document(['a'], [['a' => '1']], 'pc2gap://nowrite');
                    self::fail('a read-only buffer wrote csv');
                } catch (RuntimeException $error) {
                    self::assertSame('could not write a csv row', $error->getMessage());
                }
            });
            $this->swallow(static function (): void {
                try {
                    EnvFile::apply('pc2gap://deny');
                    self::fail('an unreadable env file was applied');
                } catch (RuntimeException $error) {
                    self::assertStringContainsString('could not be read', $error->getMessage());
                }
                try {
                    MailKeyFile::present('pc2gap://deny');
                    self::fail('an unreadable key file was read');
                } catch (RuntimeException $error) {
                    self::assertSame('the environment file could not be read', $error->getMessage());
                }
                try {
                    MailKeyFile::present('pc2gap://nowrite');
                    self::fail('a read-only key file was updated');
                } catch (RuntimeException $error) {
                    self::assertSame('the environment file could not be updated', $error->getMessage());
                }
            });
        } finally {
            stream_wrapper_unregister('pc2gap');
        }
    }

    public function testJobsDispatchQueueAndRunnerBranches(): void
    {
        $dispatch = new JobDispatch(
            new LateFeeJob($this->createStub(PDO::class)),
            new DigestJob($this->createStub(PDO::class), new OutboundMail($this->createStub(LoggerInterface::class)), new MailBounce(new Statements($this->createStub(PDO::class))), $this->createStub(LoggerInterface::class), new MailProgress()),
            new ReconcileJob($this->createStub(PDO::class)),
            new SaleNoticeJob(new Statements($this->createStub(PDO::class)), new OutboundMail($this->createStub(LoggerInterface::class)), new MailBounce(new Statements($this->createStub(PDO::class))), $this->createStub(LoggerInterface::class), new MailProgress()),
            new VendorLetterJob(new Statements($this->createStub(PDO::class)), $this->createStub(LetterMailbox::class), new MailBounce(new Statements($this->createStub(PDO::class))), $this->createStub(LoggerInterface::class)),
            new MarkdownJob($this->createStub(PDO::class)),
            new OutboundMailJob(new MailOutbox($this->createStub(PDO::class)), $this->createStub(LetterMailbox::class), $this->platformMailbox()),
        );
        try {
            $dispatch->dispatch($this->job('assess-late-fees', 'null'));
            self::fail('a null payload ran');
        } catch (RuntimeException $error) {
            self::assertSame('job payload is not an object', $error->getMessage());
        }
        try {
            $dispatch->dispatch($this->job('assess-late-fees', '[1]'));
            self::fail('a list payload ran');
        } catch (RuntimeException $error) {
            self::assertSame('job payload is not an object', $error->getMessage());
        }
        try {
            $dispatch->dispatch($this->job('vendor-digest', '{"date":"2026-06-15"}'));
        } catch (\Throwable $error) {
            self::assertNotSame('', $error->getMessage());
        }
        try {
            $dispatch->dispatch($this->job('rent-reminder', '{"date":"2026-06-15"}'));
        } catch (\Throwable $error) {
            self::assertNotSame('', $error->getMessage());
        }
        try {
            $dispatch->dispatch($this->job('outbound-mail', '{"mail":""}'));
            self::fail('a blank mail id ran');
        } catch (RuntimeException $error) {
            self::assertSame('job mail is missing', $error->getMessage());
        }

        $queue = new JobQueue();
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn(new ScriptedResult());
        try {
            $queue->enqueueLetter($pdo, 'June', self::VENDOR);
            self::fail('a bad letter day was queued');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('the letter has no day', $error->getMessage());
        }
        try {
            $queue->enqueueLetter($pdo, '2026-06-15', 'nope');
            self::fail('a bad letter id was queued');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('the letter is missing', $error->getMessage());
        }
        $open = false;
        $claim = $this->createStub(PDO::class);
        $claim->method('inTransaction')->willReturnCallback(static function () use (&$open): bool {
            return $open;
        });
        $claim->method('beginTransaction')->willReturnCallback(static function () use (&$open): bool {
            $open = true;

            return true;
        });
        $claim->method('rollBack')->willReturnCallback(static function () use (&$open): bool {
            $open = false;

            return true;
        });
        $claim->method('prepare')->willReturn(new ScriptedResult(error: new RuntimeException('claim failed')));
        try {
            $queue->claim($claim, 'worker');
            self::fail('a failed claim was returned');
        } catch (RuntimeException $error) {
            self::assertSame('claim failed', $error->getMessage());
            self::assertFalse($open);
        }
        $queue->finish($pdo, self::VENDOR, 'still waiting');

        $runnerPdo = $this->createStub(PDO::class);
        $runnerPdo->method('prepare')->willReturnCallback(static function (string $sql): PDOStatement {
            if (str_contains($sql, 'schema_name')) {
                return new ScriptedResult(column: self::SCHEMA);
            }

            return new ScriptedResult();
        });
        $roles = 0;
        $runnerPdo->method('exec')->willReturnCallback(static function (string $sql) use (&$roles): int|false {
            if (str_starts_with($sql, 'SET ROLE')) {
                $roles++;

                return $roles === 1 ? false : 0;
            }
            if (str_starts_with($sql, 'SET search_path')) {
                return false;
            }

            return 0;
        });
        $runner = new JobRunner(
            $runnerPdo,
            new JobQueue(),
            $this->createStub(LoggerInterface::class),
            new JobSchedule($runnerPdo, new JobQueue(), $this->createStub(LoggerInterface::class)),
            $dispatch,
            new WorkerRuns($runnerPdo),
        );
        try {
            $runner->handle($this->claimed('platform', 'assess-late-fees', 1));
            self::fail('a platform fee job ran');
        } catch (RuntimeException $error) {
            self::assertSame('platform jobs are mail only', $error->getMessage());
        }
        try {
            $runner->handle($this->claimed('gapmall', 'outbound-mail', 1));
            self::fail('a refused role ran');
        } catch (RuntimeException $error) {
            self::assertSame('the worker could not assume the mall role', $error->getMessage());
        }
        try {
            $runner->handle($this->claimed('gapmall', 'outbound-mail', 1));
            self::fail('a refused search path ran');
        } catch (RuntimeException $error) {
            self::assertSame('tenant is not provisioned', $error->getMessage());
        }
        $this->passOnce();

        $markdown = new MarkdownJob($this->createStub(PDO::class));
        $this->expectException(RuntimeException::class);
        $markdown->propose('June');
    }

    public function testMailBranchesThatDoNotLeaveTheMachine(): void
    {
        $env = $this->holdMailEnv();
        try {
            $logger = $this->createStub(LoggerInterface::class);
            $mail = new OutboundMail($logger);
            stream_wrapper_register('pc2gap', GapStream::class);
            putenv('STALL_MAIL_FILE=pc2gap://nowrite');
            try {
                $this->swallow(static function () use ($mail): void {
                    $mail->deliver('ada@mall.test', 'Hello', 'Body');
                });
                self::fail('a read-only mail file was written');
            } catch (RuntimeException $error) {
                self::assertSame('mail file write failed', $error->getMessage());
            } finally {
                stream_wrapper_unregister('pc2gap');
            }
            putenv('STALL_MAIL_FILE');
            putenv('STALL_SMTP_HOST=127.0.0.1');
            putenv('STALL_SMTP_PORT=1');
            putenv('STALL_SMTP_USERNAME=ada@mall.test');
            putenv('STALL_SMTP_PASSWORD=secret');
            putenv('STALL_SMTP_FROM=ada@mall.test');
            try {
                $this->swallow(static function () use ($mail): void {
                    $mail->deliver('ada@mall.test', 'Hello', 'Body');
                });
                self::fail('a closed port delivered mail');
            } catch (RuntimeException $error) {
                self::assertSame('smtp send failed', $error->getMessage());
            }

            $notice = new PlatformOperatorNotice($logger);
            $sending = new ReflectionProperty(PlatformOperatorNotice::class, 'sending');
            $sending->setValue(null, true);
            $notice->serverFailed('already sending');
            $sending->setValue(null, false);
            putenv('STALL_PLATFORM_ADMIN_EMAIL=ada@mall.test');
            putenv('STALL_GMAIL_ADDRESS');
            putenv('STALL_GMAIL_APP_PASSWORD');
            putenv('STALL_SMTP_HOST');
            putenv('STALL_SMTP_USERNAME');
            putenv('STALL_SMTP_PASSWORD');
            putenv('STALL_SMTP_FROM');
            $notice->serverFailed('no mailbox');

            $this->expectException(\InvalidArgumentException::class);
            SealedSecret::seal('');
        } finally {
            $this->restoreMailEnv($env);
        }
    }

    public function testSealedSecretAndMailboxSealFailures(): void
    {
        try {
            SealedSecret::open('v1:!!!');
            self::fail('bad base64 opened');
        } catch (RuntimeException $error) {
            self::assertSame('the mail password is not sealed', $error->getMessage());
        }
        $pdo = $this->createStub(PDO::class);
        $pdo->method('query')->willReturn(new ScriptedResult(rows: ['secret']));
        $pdo->method('prepare')->willReturn(false);
        $this->expectException(RuntimeException::class);
        MailboxSeal::sealPlain($pdo);
    }

    public function testLetterMailboxGmailAndSaleNoticeRecord(): void
    {
        $env = $this->holdMailEnv();
        ReceiptMailSettings::useTransport('127.0.0.1', 1);
        try {
            $settings = new ReceiptMailSettings(new FlexibleStatements(static fn (): array => [], static function (): array {
                return [[
                    'receipt_mail_via' => 'server',
                    'gmail_address' => '',
                    'gmail_app_password' => null,
                ]];
            }));
            $logger = $this->createStub(LoggerInterface::class);
            $mailbox = new LetterMailbox($settings, new OutboundMail($logger), new GmailSender(new PlatformOperatorNotice($logger)));
            self::assertFalse($mailbox->ready());
            $gmail = new ReceiptMailSettings(new FlexibleStatements(static fn (): array => [], static function (): array {
                return [[
                    'receipt_mail_via' => 'gmail',
                    'gmail_address' => '',
                    'gmail_app_password' => null,
                ]];
            }));
            $letters = new LetterMailbox($gmail, new OutboundMail($logger), new GmailSender(new PlatformOperatorNotice($logger)));
            try {
                $letters->send('ada@mall.test', 'Hello', 'Body');
                self::fail('missing gmail was sent');
            } catch (RuntimeException $error) {
                self::assertSame('Gmail is not set up', $error->getMessage());
            }
            $key = sodium_bin2base64(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES), SODIUM_BASE64_VARIANT_ORIGINAL);
            putenv('STALL_MAIL_KEY=' . $key);
            $sealed = new ReceiptMailSettings(new FlexibleStatements(static fn (): array => [], static function () : array {
                return [[
                    'receipt_mail_via' => 'gmail',
                    'gmail_address' => 'ada@gmail.com',
                    'gmail_app_password' => SealedSecret::seal('app-password'),
                ]];
            }));
            $sender = new LetterMailbox($sealed, new OutboundMail($logger), new GmailSender(new PlatformOperatorNotice($logger)));
            try {
                $this->swallow(static function () use ($sender): void {
                    $sender->send('ada@mall.test', 'Hello', 'Body');
                });
                self::fail('a closed gmail port delivered');
            } catch (\Throwable $error) {
                self::assertNotSame('', $error->getMessage());
            }

            $file = tempnam(sys_get_temp_dir(), 'pc2mail');
            self::assertNotFalse($file);
            putenv('STALL_MAIL_FILE=' . $file);
            $statements = new FlexibleStatements(static function (string $sql): array {
                if (str_contains($sql, 'job_mail_sent')) {
                    return [];
                }

                return [[
                    'email' => 'ada@mall.test',
                    'name' => 'Ada',
                    'code' => '100',
                    'net_minor' => 100,
                ]];
            });
            (new SaleNoticeJob(
                $statements,
                new OutboundMail($logger),
                new MailBounce($statements),
                $logger,
                new MailProgress(),
            ))->notice('gapmall', '2026-06-15');
            unlink($file);
        } finally {
            ReceiptMailSettings::useTransport('smtp.gmail.com', 587);
            $this->restoreMailEnv($env);
        }
    }

    public function testOutboundMailJobVendorLetterAndMailAttempt(): void
    {
        $missing = new OutboundMailJob(
            new MailOutbox($this->createStub(PDO::class)),
            $this->createStub(LetterMailbox::class),
            $this->platformMailbox(),
        );
        try {
            $missing->send('nope');
            self::fail('a missing mail was sent');
        } catch (RuntimeException $error) {
            self::assertSame('a mail is missing', $error->getMessage());
        }
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn(new ScriptedResult(rows: [[
            'id' => self::VENDOR,
            'tenant' => 'gapmall',
            'mailbox' => 'nope',
            'purpose' => 'note',
            'address' => 'ada@mall.test',
            'subject' => 'Hello',
            'body' => 'Body',
            'sent_at' => '',
        ]]));
        try {
            (new OutboundMailJob(
                new MailOutbox($pdo),
                $this->createStub(LetterMailbox::class),
                $this->platformMailbox(),
            ))->send(self::VENDOR);
            self::fail('an unknown mailbox was sent');
        } catch (RuntimeException $error) {
            self::assertSame('the mailbox is not a mailbox', $error->getMessage());
        }
        $badId = $this->createStub(PDO::class);
        $badId->method('prepare')->willReturn(new ScriptedResult(column: 'not-a-uuid'));
        try {
            (new MailOutbox($badId))->file('gapmall', 'mall', 'note', 'ada@mall.test', 'Hello', 'Body');
            self::fail('a bad mail id was filed');
        } catch (RuntimeException $error) {
            self::assertSame('the mail could not be filed', $error->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        (new MailOutbox($this->createStub(PDO::class)))->markSent('nope');
    }

    public function testVendorLetterBouncesAndMailAttemptRethrows(): void
    {
        $letters = $this->createStub(LetterMailbox::class);
        $letters->method('send')->willThrowException(new MailDeliveryFailed(550, 'rcpt'));
        $statements = new FlexibleStatements(static function (string $sql): array {
            if (str_contains($sql, 'vendor_letter_copies')) {
                return [['vendor_id' => self::VENDOR, 'email' => 'ada@mall.test']];
            }

            return [['subject' => 'Hello', 'body' => 'Body']];
        });
        (new VendorLetterJob($statements, $letters, new MailBounce($statements), $this->createStub(LoggerInterface::class)))->send(self::VENDOR);
        self::assertStringContainsString('email_bounced', $statements->lastChange);
        try {
            (new VendorLetterJob($statements, $letters, new MailBounce($statements), $this->createStub(LoggerInterface::class)))->send('nope');
            self::fail('a bad letter id was sent');
        } catch (RuntimeException $error) {
            self::assertSame('a letter is missing', $error->getMessage());
        }
        try {
            (new VendorLetterJob(new FlexibleStatements(static fn (): array => []), $letters, new MailBounce($statements), $this->createStub(LoggerInterface::class)))->send(self::VENDOR);
            self::fail('a missing letter was sent');
        } catch (RuntimeException $error) {
            self::assertSame('a letter is missing', $error->getMessage());
        }

        $env = $this->holdMailEnv();
        $stand = $this->listen("550 no such user\r\n");
        try {
            putenv('STALL_MAIL_FILE');
            putenv('STALL_SMTP_HOST=127.0.0.1');
            putenv('STALL_SMTP_PORT=' . (string) $stand['port']);
            putenv('STALL_SMTP_USERNAME=ada@mall.test');
            putenv('STALL_SMTP_PASSWORD=secret');
            putenv('STALL_SMTP_FROM=ada@mall.test');
            try {
                MailAttempt::send(
                    new OutboundMail($this->createStub(LoggerInterface::class)),
                    new MailBounce(new Statements($this->createStub(PDO::class))),
                    $this->createStub(LoggerInterface::class),
                    'ada@mall.test',
                    'Hello',
                    'Body',
                );
                self::fail('a refused greeting was delivered');
            } catch (MailDeliveryFailed $error) {
                self::assertSame('greeting', $error->stage());
            }
            $client = new SmtpClient('127.0.0.1', $stand['port'], 'ada', 'secret', 'ada@mall.test');
            $read = new ReflectionMethod(SmtpClient::class, 'read');
            $write = new ReflectionMethod(SmtpClient::class, 'write');
            try {
                $read->invoke($client, 'nope');
                self::fail('a string was read as a socket');
            } catch (RuntimeException $error) {
                self::assertSame('smtp send failed', $error->getMessage());
            }
            try {
                $write->invoke($client, 'nope', "EHLO\r\n");
                self::fail('a string was written as a socket');
            } catch (RuntimeException $error) {
                self::assertSame('smtp send failed', $error->getMessage());
            }
        } finally {
            $this->stop($stand);
            $this->restoreMailEnv($env);
        }
    }

    /**
     * @return array{cash: string, card: string, checks: string, tendered: string, check: string}
     */
    private function blankTender(): array
    {
        return ['cash' => '0', 'card' => '0', 'checks' => '0', 'tendered' => '', 'check' => ''];
    }

    private function posting(bool $prepaid, bool $quote): RentPosting
    {
        $statements = new FlexibleStatements(static fn (): array => [], static function () use ($prepaid): array {
            return [[
                'operating_mode' => 'both',
                'payout_cadence' => 'monthly',
                'rent_prepaid_from_prior_sales' => $prepaid,
                'booth_rentals_enabled' => true,
                'rent_cycle_day' => 1,
                'consignment_enabled' => true,
                'store_stock_enabled' => false,
                'late_fees_enabled' => false,
            ]];
        });
        $quoted = new FlexibleStatements(
            static function (string $sql) use ($quote): array {
                if (str_contains($sql, 'FROM payout_runs')) {
                    return [['id' => MallDoor::RUN]];
                }
                if ($quote && str_contains($sql, 'stall_payout_run_quote')) {
                    return [[
                        'payable' => 0,
                        'rent' => 0,
                        'check_amount' => 0,
                        'owe_amount' => 100,
                        'settled_minor' => 0,
                        'rent_from_sales' => false,
                    ]];
                }

                return [];
            },
            static fn (): array => [['rent_cycle_day' => 1]],
        );
        $runs = new PayoutRunStore($quoted, new OccupiedPack($quoted));
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('newId')->willReturn(self::VENDOR);
        $ledger->method('roleAccount')->willReturn(self::ACCOUNT);
        $ledger->method('post')->willReturn(self::VENDOR);
        $payouts = new PayoutStore($this->createStub(PDO::class), $quoted, $runs, new PayoutWindow());

        return new RentPosting(
            $ledger,
            $payouts,
            new PayoutSettler($ledger, $payouts, $runs, new \PerfectConsign\Feature\Payout\PayoutRunLines($quoted), new \PerfectConsign\Bootstrap\JournalAudit(new Actor($this->createStub(\PerfectApp\Session\Session::class)), new \PerfectConsign\Bootstrap\AuditLog($quoted)), new \PerfectConsign\Feature\Payout\VendorClawback($ledger, $quoted)),
            new MallPayoutSettings($statements),
            new \PerfectConsign\Feature\Rent\RentMoves($statements, new MallPayoutSettings($statements)),
        );
    }

    private function receipt(bool $open): RentReceipt
    {
        $statements = new FlexibleStatements(static function (string $sql) use ($open): array {
            if (!$open) {
                return [];
            }
            if (str_contains($sql, 'late_minor')) {
                return [['late_minor' => 0]];
            }
            if (str_contains($sql, 'open_minor')) {
                return [['open_minor' => 500]];
            }
            if (str_contains($sql, 'vendor_name')) {
                return [['vendor_name' => 'Ada']];
            }
            if (str_contains($sql, 'booth.code')) {
                return [['id' => self::VENDOR, 'code' => 'A1']];
            }

            return [];
        }, static fn (): array => [[
            'rent_prepaid_from_prior_sales' => false,
            'rent_cycle_day' => 1,
            'operating_mode' => 'both',
        ]]);
        $ledger = $this->createStub(LedgerStore::class);
        $posting = new RentPosting(
            $ledger,
            new PayoutStore($this->createStub(PDO::class), $statements, new PayoutRunStore($statements, new OccupiedPack($statements)), new PayoutWindow()),
            new PayoutSettler($ledger, new PayoutStore($this->createStub(PDO::class), $statements, new PayoutRunStore($statements, new OccupiedPack($statements)), new PayoutWindow()), new PayoutRunStore($statements, new OccupiedPack($statements)), new \PerfectConsign\Feature\Payout\PayoutRunLines($statements), new \PerfectConsign\Bootstrap\JournalAudit(new Actor($this->createStub(\PerfectApp\Session\Session::class)), new \PerfectConsign\Bootstrap\AuditLog($statements)), new \PerfectConsign\Feature\Payout\VendorClawback($ledger, $statements)),
            new MallPayoutSettings($statements),
            new \PerfectConsign\Feature\Rent\RentMoves($statements, new MallPayoutSettings($statements)),
        );

        return new RentReceipt(
            $this->createStub(PDO::class),
            $statements,
            new PayoutStore($this->createStub(PDO::class), $statements, new PayoutRunStore($statements, new OccupiedPack($statements)), new PayoutWindow()),
            new MallPayoutSettings($statements),
            $posting,
            new RentSlip($statements),
            new RentPayment(),
            new LateFee($ledger, $statements),
            $this->createStub(\PerfectConsign\Bootstrap\SaleCeiling::class),
        );
    }

    private function mallSettings(): \PerfectConsign\Feature\Mall\MallSettings
    {
        $statements = new FlexibleStatements(static fn (): array => [], static fn (): array => [[
            'time_zone' => 'America/Los_Angeles',
            'booth_width_ft' => 10,
            'booth_depth_ft' => 10,
            'floor_width_ft' => 40,
            'floor_depth_ft' => 40,
            'commission_bps' => 4000,
            'floor_days' => 60,
            'cash_round_minor' => 0,
            'tax_bps' => 800,
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
        ]]);

        return new \PerfectConsign\Feature\Mall\MallSettings(
            $statements,
            new \PerfectConsign\Feature\Company\CompanyStore($statements, new DatabaseRole($this->createStub(PDO::class)), $this->createStub(PDO::class), new \PerfectConsign\Feature\Company\CompanyLines()),
            new \PerfectConsign\Feature\Mall\MallDay($statements),
        );
    }

    private function sheets(MallDoor $door): ReportSheetsController
    {
        return new ReportSheetsController($door->app, $door->actor, new LedgerReport($door->statements), new ReportMonth());
    }

    private function reports(MallDoor $door): ReportsController
    {
        return new ReportsController(
            $door->app,
            $door->actor,
            new SalesTaxSheet($door->statements),
            new SalesTaxFiling(),
            new LedgerReport($door->statements),
            new ReportMonth(),
            new SavedReport($door->statements, new ReportCatalog()),
        );
    }

    private function csvController(MallDoor $door): ReportCsvController
    {
        return new ReportCsvController(
            $door->app,
            $door->actor,
            new SalesTaxSheet($door->statements),
            new LedgerReport($door->statements),
            new ReportMonth(),
        );
    }

    private function preview(MallDoor $door): VendorPreview
    {
        $parties = $this->createStub(PartyStore::class);
        $parties->method('findVendor')->willReturn(null);

        return new VendorPreview($door->app, $door->actor, $parties);
    }

    private function stickers(MallDoor $door): VendorStickers
    {
        $statements = $door->statements;
        $account = new VendorAccount(
            $statements,
            new ItemQueries($statements),
            new ItemForm(new IntakeCatalog($statements), new NameListStore($statements, new CatalogLinks($statements)), $door->mall, new StoreTake()),
            new StoreTake(),
        );

        return new VendorStickers(
            $door->app,
            $account,
            new InventoryStore($statements, new CatalogLinks($statements), $door->mall),
            $door->mall,
            new LabelRequest(new LabelCatalog()),
            new LabelSheet(new Code128(), new BarcodePng(), new LabelFace(new Code128(), $door->mall)),
            new StickerFromRow(),
        );
    }

    private function home(MallDoor $door): VendorHomeController
    {
        $statements = new FlexibleStatements(static function (string $sql): array {
            if (str_contains($sql, 'vendor_statement')) {
                return [[
                    'role' => 'vendor_payable',
                    'debit_minor' => 0,
                    'credit_minor' => 100,
                    'run_id' => null,
                    'posted_on' => '2026-06-15',
                    'source_type' => 'vendor_memo',
                    'is_reversal' => false,
                ]];
            }
            if (str_contains($sql, 'check_minor')) {
                return [[
                    'paid_on' => '2026-06-15',
                    'check_minor' => 100,
                    'check_number' => '44',
                ]];
            }

            return [];
        });
        $checks = new VendorCheck(
            $statements,
            new PayoutStore($door->pdo, $statements, new PayoutRunStore($statements, new OccupiedPack($statements)), new PayoutWindow()),
            new VendorAccount($statements, new ItemQueries($statements), new ItemForm(new IntakeCatalog($statements), new NameListStore($statements, new CatalogLinks($statements)), $door->mall, new StoreTake()), new StoreTake()),
            $door->mall,
        );

        return new VendorHomeController(
            $door->app,
            $this->preview($door),
            new VendorAccount($door->statements, new ItemQueries($door->statements), new ItemForm(new IntakeCatalog($door->statements), new NameListStore($door->statements, new CatalogLinks($door->statements)), $door->mall, new StoreTake()), new StoreTake()),
            $checks,
            new VendorSales($door->statements),
            new PayoutWindow(),
            $door->mall,
            new LabelCatalog(),
            $this->stickers($door),
        );
    }

    private function entryController(MallDoor $door): VendorEntryController
    {
        return new VendorEntryController(
            $door->app,
            $door->session,
            new VendorEntry(
                $door->pdo,
                $door->roles,
                $door->statements,
                $this->createStub(LetterMailbox::class),
                new MailOutbox($door->pdo),
                $this->createStub(LoggerInterface::class),
                $door->app->request(),
            ),
            new VendorPortal($door->pdo, $door->roles, $door->statements),
        );
    }

    private function tenant(string $schema): TenantRecord
    {
        return new TenantRecord(MallDoor::TENANT, MallDoor::SLUG, $schema, 'poster_abababababababababababababababab', 'Gap');
    }

    private function execPdo(bool $searchPath): PDO
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willReturnCallback(static function (string $sql) use ($searchPath): int|false {
            if (!$searchPath && str_starts_with($sql, 'SET search_path')) {
                return false;
            }

            return 0;
        });

        return $pdo;
    }

    private function ownerPdo(?\Throwable $error, int $count, string $throwSql = 'password_hash'): PDO
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willReturn(0);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($error, $count, $throwSql): PDOStatement {
            return new ScriptedResult(count: $count, error: $error !== null && str_contains($sql, $throwSql) ? $error : null);
        });

        return $pdo;
    }

    /**
     * @return array<string, mixed>
     */
    private static function waitRow(string $status): array
    {
        return [
            'id' => self::VENDOR,
            'first_name' => 'Ada',
            'last_name' => 'Lane',
            'status' => $status,
            'email' => 'ada@mall.test',
            'phone' => '5555550100',
            'width_ft' => null,
            'depth_ft' => null,
            'notes' => null,
            'waiting_since' => '2026-01-01',
            'waited_days' => 1,
        ];
    }

    private function root(MallDoor $door, string $hash, bool $verified, \PerfectApp\Session\Session $actorSession): RootController
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willReturn(0);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(false);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($hash, $verified): PDOStatement {
            if (str_contains($sql, 'sign_in_directory')) {
                return new ScriptedResult(rows: [['slug' => MallDoor::SLUG, 'username' => 'ada']]);
            }
            if (str_contains($sql, 'platform.tenants')) {
                return new ScriptedResult(rows: [[
                    'id' => MallDoor::TENANT,
                    'slug' => MallDoor::SLUG,
                    'schema_name' => self::SCHEMA,
                    'display_name' => 'Gap',
                ]]);
            }
            if (str_contains($sql, 'platform.owners')) {
                return new ScriptedResult(rows: [[
                    'id' => self::ACCOUNT,
                    'password_hash' => $hash,
                    'credentials_version' => '1',
                    'email_verified_at' => $verified ? '2026-01-01' : null,
                    'can_sign_in' => true,
                ]]);
            }
            if (str_contains($sql, 'platform.settings')) {
                return new ScriptedResult(column: false);
            }

            return new ScriptedResult(column: 0);
        });
        $logger = $this->createStub(LoggerInterface::class);
        $sessions = new LoginSession($door->session);
        $tenants = new TenantLookup($pdo);
        $owner = new OwnerGate(
            $pdo,
            $door->session,
            $logger,
            $door->app->request(),
            $tenants,
            new \PerfectConsign\Bootstrap\MissingPage($door->session, $logger, new View(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src'), $door->app->request(), $tenants),
            new StaffSignIn($pdo, $logger, $sessions, new SignInFailureRetention($pdo)),
            $sessions,
        );
        $roles = new DatabaseRole($pdo);
        $mailbox = $this->createStub(LetterMailbox::class);

        return new RootController(
            $door->app,
            $door->session,
            $tenants,
            new EmailSignIn($pdo, $door->app->request(), $logger, new SignInDirectory($pdo), new FrontDoor(
                new \PerfectConsign\Bootstrap\MissingPage($door->session, $logger, new View(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src'), $door->app->request(), $tenants),
                new \PerfectConsign\Bootstrap\PriorClose($door->session, $door->app->request()),
                $owner,
                new \PerfectConsign\Bootstrap\PostedRefusal($logger),
                new Actor($door->session),
                new \PerfectConsign\Bootstrap\CredentialStamp($pdo, $door->session, $roles),
            ), new SignInFailureRetention($pdo)),
            new SignInDirectory($pdo),
            new PasswordRecovery(
                $pdo,
                $roles,
                new LoginAccounts($pdo, $roles),
                new ResetTokens($pdo),
                new ResetRequests($pdo, $door->app->request()),
                new PasswordChoice(),
                new ResetLetter($pdo, $roles, new ResetTokens($pdo), $mailbox, $logger, new PlatformOperatorNotice($logger)),
            ),
            new Actor($actorSession),
            new PlatformSettings($pdo, $logger),
        );
    }

    private function ledger(bool $statement, mixed $column): LedgerStore
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('inTransaction')->willReturn(false);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('exec')->willReturn(0);
        $pdo->method('query')->willReturn($statement ? new ScriptedResult(column: $column) : false);

        return new LedgerStore($pdo, new Statements($pdo), new LedgerPeriods($pdo), new JournalCash($pdo));
    }

    private function draft(): \PerfectConsign\Ledger\JournalDraft
    {
        return new \PerfectConsign\Ledger\JournalDraft(
            'listed',
            '2026-06-15',
            new \PerfectConsign\Ledger\JournalSource('rent_payment', 'listed', 'Listed'),
            [],
        );
    }

    /**
     * @return array{id: string, tenant: string, kind: string, payload: string, attempts: int}
     */
    private function job(string $kind, string $payload): array
    {
        return $this->claimed('gapmall', $kind, 1, $payload);
    }

    public function testSmtpRejectsANonGreetingAndADeadSocket(): void
    {
        $stand = $this->listen("250 ok\r\n");
        try {
            $client = new SmtpClient('127.0.0.1', $stand['port'], 'ada@mall.test', 'secret', 'ada@mall.test');
            $client->send('ada@mall.test', 'Hello', 'Body');
            self::fail('a 250 greeting was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('smtp send failed', $error->getMessage());
        } finally {
            $this->stop($stand);
        }
        $read = new ReflectionMethod(SmtpClient::class, 'read');
        $write = new ReflectionMethod(SmtpClient::class, 'write');
        $client = new SmtpClient('127.0.0.1', 1, 'ada@mall.test', 'secret', 'ada@mall.test');
        try {
            $read->invoke($client, null);
            self::fail('a missing socket was read');
        } catch (RuntimeException $error) {
            self::assertSame('smtp send failed', $error->getMessage());
        }
        try {
            $write->invoke($client, null, "EHLO\r\n");
            self::fail('a missing socket was written');
        } catch (RuntimeException $error) {
            self::assertSame('smtp send failed', $error->getMessage());
        }
    }

    private function platformMailbox(): PlatformMailbox
    {
        $logger = $this->createStub(LoggerInterface::class);

        return new PlatformMailbox(
            new OutboundMail($logger),
            new GmailSender(new PlatformOperatorNotice($logger)),
            new PlatformOperatorNotice($logger),
        );
    }

    private function passOnce(): void
    {
        $claims = 0;
        $pdo = $this->createStub(PDO::class);
        $pdo->method('query')->willReturnCallback(static function (string $sql): PDOStatement {
            if (str_contains($sql, 'count(*)')) {
                return new ScriptedResult(column: 2);
            }

            return new ScriptedResult();
        });
        $pdo->method('prepare')->willReturnCallback(static function (string $sql) use (&$claims): PDOStatement {
            if (str_contains($sql, 'FOR UPDATE SKIP LOCKED')) {
                $claims++;
                if ($claims === 1) {
                    return new ScriptedResult(rows: [[
                        'id' => self::VENDOR,
                        'tenant' => 'platform',
                        'kind' => 'assess-late-fees',
                        'payload' => '{}',
                        'attempts' => 5,
                    ]]);
                }
            }

            return new ScriptedResult();
        });
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(false);
        $pdo->method('exec')->willReturn(0);
        $runner = new JobRunner(
            $pdo,
            new JobQueue(),
            $this->createStub(LoggerInterface::class),
            new JobSchedule($pdo, new JobQueue(), $this->createStub(LoggerInterface::class)),
            new JobDispatch(
                new LateFeeJob($this->createStub(PDO::class)),
                new DigestJob($this->createStub(PDO::class), new OutboundMail($this->createStub(LoggerInterface::class)), new MailBounce(new Statements($this->createStub(PDO::class))), $this->createStub(LoggerInterface::class), new MailProgress()),
                new ReconcileJob($this->createStub(PDO::class)),
                new SaleNoticeJob(new Statements($this->createStub(PDO::class)), new OutboundMail($this->createStub(LoggerInterface::class)), new MailBounce(new Statements($this->createStub(PDO::class))), $this->createStub(LoggerInterface::class), new MailProgress()),
                new VendorLetterJob(new Statements($this->createStub(PDO::class)), $this->createStub(LetterMailbox::class), new MailBounce(new Statements($this->createStub(PDO::class))), $this->createStub(LoggerInterface::class)),
                new MarkdownJob($this->createStub(PDO::class)),
                new OutboundMailJob(new MailOutbox($this->createStub(PDO::class)), $this->createStub(LetterMailbox::class), $this->platformMailbox()),
            ),
            new WorkerRuns($pdo),
        );
        $runner->pass('worker');
        self::assertSame(2, $claims);
    }

    /**
     * @return array{id: string, tenant: string, kind: string, payload: string, attempts: int}
     */
    private function claimed(string $tenant, string $kind, int $attempts, string $payload = '{}'): array
    {
        return [
            'id' => self::VENDOR,
            'tenant' => $tenant,
            'kind' => $kind,
            'payload' => $payload,
            'attempts' => $attempts,
        ];
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function swallow(callable $work): mixed
    {
        set_error_handler(static function (): bool {
            return true;
        });
        try {
            return $work();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @return array<string, string|false>
     */
    private function holdMailEnv(): array
    {
        $held = [];
        foreach ([
            'STALL_MAIL_FILE',
            'STALL_SMTP_HOST',
            'STALL_SMTP_PORT',
            'STALL_SMTP_USERNAME',
            'STALL_SMTP_PASSWORD',
            'STALL_SMTP_FROM',
            'STALL_PLATFORM_ADMIN_EMAIL',
            'STALL_GMAIL_ADDRESS',
            'STALL_GMAIL_APP_PASSWORD',
            'STALL_MAIL_KEY',
        ] as $name) {
            $held[$name] = getenv($name);
            putenv($name);
        }

        return $held;
    }

    /**
     * @param array<string, string|false> $held
     */
    private function restoreMailEnv(array $held): void
    {
        foreach ($held as $name => $value) {
            if (is_string($value) && $value !== '') {
                putenv($name . '=' . $value);
                continue;
            }
            putenv($name);
        }
    }

    private function fatalScript(): string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);
require $argv[1];
$logger = new class implements Psr\Log\LoggerInterface {
    public function emergency(Stringable|string $message, array $context = []): void {}
    public function alert(Stringable|string $message, array $context = []): void {}
    public function critical(Stringable|string $message, array $context = []): void {}
    public function error(Stringable|string $message, array $context = []): void {}
    public function warning(Stringable|string $message, array $context = []): void {}
    public function notice(Stringable|string $message, array $context = []): void {}
    public function info(Stringable|string $message, array $context = []): void {}
    public function debug(Stringable|string $message, array $context = []): void {}
    public function log($level, Stringable|string $message, array $context = []): void {}
};
PerfectConsign\Shared\Http\ErrorHandling::register($logger, null);
ob_start();
listed_line_undefined_function();
PHP;
    }

    /**
     * @return array{process: resource, pipes: list<resource>, files: list<string>, port: int}
     */
    private function listen(string $line): array
    {
        $script = tempnam(sys_get_temp_dir(), 'pc2smtp');
        $portFile = tempnam(sys_get_temp_dir(), 'pc2port');
        self::assertNotFalse($script);
        self::assertNotFalse($portFile);
        $body = <<<'PHP'
<?php
declare(strict_types=1);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) { exit(1); }
$name = stream_socket_get_name($server, false);
if (!is_string($name) || file_put_contents($argv[1], $name) === false) { exit(1); }
$client = stream_socket_accept($server, 5);
if ($client === false) { exit(1); }
fwrite($client, $argv[2]);
fclose($client);
fclose($server);
PHP;
        self::assertNotFalse(file_put_contents($script, $body));
        $pipes = [];
        $process = proc_open([PHP_BINARY, $script, $portFile, $line], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertNotFalse($process);
        $opened = [];
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                $opened[] = $pipe;
            }
        }
        $deadline = microtime(true) + 3;
        $port = 0;
        while (microtime(true) < $deadline) {
            $text = is_file($portFile) ? file_get_contents($portFile) : false;
            if (is_string($text) && preg_match('/:(\d+)$/', trim($text), $match) === 1) {
                $port = (int) $match[1];
                break;
            }
            usleep(20000);
        }
        self::assertGreaterThan(0, $port);

        return ['process' => $process, 'pipes' => $opened, 'files' => [$script, $portFile], 'port' => $port];
    }

    /**
     * @param array{process: resource, pipes: list<resource>, files: list<string>, port: int} $stand
     */
    private function stop(array $stand): void
    {
        foreach ($stand['pipes'] as $pipe) {
            fclose($pipe);
        }
        proc_close($stand['process']);
        foreach ($stand['files'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}

final class FlexibleStatements extends Statements
{
    public string $lastChange = '';

    /**
     * @param callable(string): list<array<string, mixed>> $bound
     * @param callable(string): list<array<string, mixed>>|null $rows
     * @param callable(string): int|null $affected
     */
    public function __construct(
        private $bound,
        private $rows = null,
        private $affected = null,
    ) {
        parent::__construct(new PDO('sqlite::memory:'));
    }

    public function change(string $sql, array $params): void
    {
        $this->lastChange = $sql;
    }

    public function affected(string $sql, array $params): int
    {
        if ($this->affected === null) {
            return 1;
        }

        return ($this->affected)($sql);
    }

    public function rows(string $sql): array
    {
        if ($this->rows === null) {
            return [];
        }

        return ($this->rows)($sql);
    }

    public function bound(string $sql, array $params): array
    {
        return ($this->bound)($sql);
    }

    public function transaction(callable $work): void
    {
        $work();
    }
}

final class ScriptedResult extends PDOStatement
{
    /**
     * @param list<mixed> $rows
     */
    public function __construct(
        private readonly array $rows = [],
        private readonly mixed $column = false,
        private readonly int $count = 1,
        private readonly ?\Throwable $error = null,
    ) {
    }

    /**
     * @param array<int, mixed>|null $params
     */
    public function execute(?array $params = null): bool
    {
        if ($this->error !== null) {
            throw $this->error;
        }

        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $row = $this->rows[0] ?? false;

        return $row === false ? false : $row;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->rows;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->column;
    }

    public function rowCount(): int
    {
        return $this->count;
    }
}

final class GapStream
{
    public mixed $context = null;

    private int $pos = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool
    {
        if (str_contains($path, '://deny') || str_contains($path, '://gap')) {
            return false;
        }
        $this->pos = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $body = "OTHER=1\n";
        $slice = substr($body, $this->pos, $count);
        $this->pos += strlen($slice);

        return $slice;
    }

    public function stream_eof(): bool
    {
        return $this->pos >= strlen("OTHER=1\n");
    }

    /**
     * @return array{mode: int, size: int, mtime: int}
     */
    public function stream_stat(): array
    {
        return $this->stat();
    }

    /**
     * @return array{mode: int, size: int, mtime: int}
     */
    public function url_stat(string $path, int $flags): array
    {
        return $this->stat();
    }

    /**
     * @return array{mode: int, size: int, mtime: int}
     */
    private function stat(): array
    {
        return ['mode' => 0100644, 'size' => 8, 'mtime' => time()];
    }
}
