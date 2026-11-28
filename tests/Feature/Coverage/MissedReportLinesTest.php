<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Coverage;

use DateTimeImmutable;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\AuditLog;
use PerfectConsign\Bootstrap\AuditSubject;
use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Bootstrap\JournalAudit;
use PerfectConsign\Bootstrap\SaleCeiling;
use PerfectConsign\Feature\Activity\ActivityController;
use PerfectConsign\Feature\Activity\ActivityCursor;
use PerfectConsign\Feature\Activity\ActivityFilter;
use PerfectConsign\Feature\Activity\ActivityHref;
use PerfectConsign\Feature\Activity\ActivityLog;
use PerfectConsign\Feature\Activity\ActivityPeople;
use PerfectConsign\Feature\Activity\ActivityPhrase;
use PerfectConsign\Feature\Admin\AdminPath;
use PerfectConsign\Feature\Admin\AdminRouter;
use PerfectConsign\Feature\Admin\AdminSession;
use PerfectConsign\Feature\Close\CloseController;
use PerfectConsign\Feature\Close\CloseStore;
use PerfectConsign\Feature\Dev\DemoSubject;
use PerfectConsign\Feature\Mall\MallClock;
use PerfectConsign\Feature\Offer\OfferStore;
use PerfectConsign\Feature\Pos\BoothCorrection;
use PerfectConsign\Feature\Register\RegisterActivity;
use PerfectConsign\Feature\Register\RegisterSession;
use PerfectConsign\Feature\Report\LedgerReport;
use PerfectConsign\Feature\Report\ReportCalendar;
use PerfectConsign\Feature\Report\ReportCatalog;
use PerfectConsign\Feature\Report\ReportWords;
use PerfectConsign\Feature\Report\ReportColumn;
use PerfectConsign\Feature\Report\ReportFetch;
use PerfectConsign\Feature\Report\ReportPreset;
use PerfectConsign\Feature\Report\ReportRows;
use PerfectConsign\Feature\Report\ReportSpan;
use PerfectConsign\Feature\Report\ReportsController;
use PerfectConsign\Feature\Report\ReportTable;
use PerfectConsign\Feature\Report\ReportTie;
use PerfectConsign\Feature\Report\SalesTaxFiling;
use PerfectConsign\Feature\Report\SalesTaxReport;
use PerfectConsign\Feature\Report\SalesTaxSheet;
use PerfectConsign\Feature\Report\SavedReport;
use PerfectConsign\Feature\Staff\MallOwners;
use PerfectConsign\Feature\Staff\StaffController;
use PerfectConsign\Feature\Staff\StaffStore;
use PerfectConsign\Feature\Password\PasswordChoice;
use PerfectConsign\Feature\Password\RecoveryAddress;
use PerfectConsign\Feature\Tax\TaxController;
use PerfectConsign\Feature\Tax\TaxStore;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Http\ProbeBanStore;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\RequestId;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Jobs\JobAttention;
use PerfectConsign\Shared\Jobs\JobRunner;
use PerfectConsign\Shared\Mail\GmailSender;
use PerfectConsign\Shared\Mail\OutboundMail;
use PerfectConsign\Shared\Mail\PlatformMailbox;
use PerfectConsign\Shared\Mail\PlatformOperatorNotice;
use PerfectConsign\Shared\Mail\SmtpClient;
use PerfectConsign\Shared\Tenant\TenantRecord;
use PerfectConsign\Tests\Support\AdminDoor;
use PerfectConsign\Tests\Support\QuietAudit;
use PerfectApp\Container\Container;
use PDO;
use PDOStatement;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

final class MissedReportLinesTest extends TestCase
{
    use CapturesPage;

    public function testReportWordsNameEveryTenderPaymentAndSection(): void
    {
        $words = new ReportWords();
        self::assertSame('Cash', $words->tender('cash'));
        self::assertSame('Card', $words->tender('card'));
        self::assertSame('Check', $words->tender('checks'));
        self::assertSame('Gift certificate', $words->tender('gift_certificate'));
        self::assertSame('Gift card', $words->tender('gift_card'));
        self::assertSame('Store credit', $words->tender('store_credit'));
        self::assertSame('Check', $words->payment('check_disbursement'));
        self::assertSame('Cash', $words->payment('cash_disbursement'));
        self::assertSame('From the safe', $words->payment('safe_disbursement'));
        self::assertSame('Settled against rent', $words->payment('settlement'));
        self::assertSame('Gift card', $words->stored('gift_card'));
        self::assertSame('Gift certificate', $words->stored('gift_certificate'));
        self::assertSame('Store credit', $words->stored('store_credit'));
        self::assertSame('8.00%', $words->rate('8000'));
        self::assertSame('Taxable', $words->section('taxable'));
        self::assertSame('Exempt', $words->section('exempt'));
        self::assertSame('Returns', $words->section('return'));
        self::assertSame('Voids', $words->section('void'));
        self::assertSame('Exempt voids', $words->section('void_exempt'));
        self::assertSame('Rate', $words->section('rate'));
        self::assertSame('Not taxable', $words->section('nontaxable'));
        self::assertSame('Liability', $words->section('liability'));
        self::assertSame('Started the month', $words->liability('Opening'));
        self::assertSame('Collected', $words->liability('Collected'));
        self::assertSame('Paid to the state', $words->liability('Paid'));
        self::assertSame('Still owe', $words->liability('Closing'));
        self::assertSame('Not recorded', $words->recorded(null));
        self::assertSame('Ada', $words->recorded('Ada'));
        foreach ([
            static fn (): string => $words->tender('nope'),
            static fn (): string => $words->payment('nope'),
            static fn (): string => $words->stored('nope'),
            static fn (): string => $words->rate('8.5'),
            static fn (): string => $words->section('nope'),
            static fn (): string => $words->liability('nope'),
        ] as $refused) {
            try {
                $refused();
                self::fail('a missing label was shown');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('not on this page', $error->getMessage());
            }
        }
    }

    public function testReportRowsGroupTendersAndRecordAMissingVendor(): void
    {
        $rows = new ReportRows();
        $tender = $rows->sales('tender', [[
            'label' => 'cash',
            'merchandise_minor' => 0,
            'tax_minor' => 0,
            'receipt_count' => 1,
            'taken_minor' => 1200,
        ]]);
        self::assertSame('Cash', $tender->rows[0]['label']);
        $donated = $rows->donated([[
            'posted_on' => '2026-11-01',
            'code' => 't1',
            'description' => 'Lamp',
            'vendor_name' => null,
            'quantity' => 1,
        ]]);
        self::assertSame('Not recorded', $donated->rows[0]['vendor_name']);
        try {
            $rows->sales('booth', []);
            self::fail('an unknown grouping was shown');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('That grouping is not on this page.', $error->getMessage());
        }
    }

    public function testANamedRangeABadHourAndAPinnedPageAreRefused(): void
    {
        $preset = new ReportPreset();
        self::assertSame(['2026-11-19', '2026-11-19'], $preset->range('yesterday', '2026-11-20'));
        self::assertSame(['2026-11-16', '2026-11-20'], $preset->range('this_week', '2026-11-20'));
        self::assertSame(['2026-10-01', '2026-10-31'], $preset->range('last_month', '2026-11-20'));
        self::assertSame('2026-11-16', $preset->shift('2026-11-20', 'monday this week'));
        $calendar = new ReportCalendar();
        $badHour = $calendar->hour(new Request('GET', '/sales', ['day' => '2026-02-31'], []), '2026-11-20', 'day=2026-02-31');
        self::assertSame('Enter a real date.', $badHour->error);
        $yesterday = $calendar->hour(new Request('GET', '/sales', ['range' => 'yesterday'], []), '2026-11-20', 'range=yesterday');
        self::assertSame('2026-11-19', $yesterday->from);
        $mixed = $calendar->bounds(new Request('GET', '/sales', ['range' => 'today', 'from' => '2026-11-01'], []), '2026-11-20');
        self::assertSame('Choose a range or two dates.', $mixed->error);
        $oneDate = $calendar->bounds(new Request('GET', '/sales', ['from' => '2026-11-01'], []), '2026-11-20');
        self::assertSame('Choose a range or two dates.', $oneDate->error);
        $named = $calendar->bounds(new Request('GET', '/sales', ['range' => 'nope'], []), '2026-11-20');
        self::assertSame('Choose a range or two dates.', $named->error);

        $span = new ReportSpan();
        self::assertSame('That filter is not on this page.', $span->sales(new Request('GET', '/sales', ['extra' => '1'], []), '2026-11-20')->error);
        self::assertSame('That filter is not on this page.', $span->sales(new Request('GET', '/sales', ['day' => '2026-11-20'], []), '2026-11-20')->error);
        self::assertSame('That filter is not on this page.', $span->ranged(new Request('GET', '/sales', ['extra' => '1'], []), '2026-11-20')->error);
        self::assertSame('That filter is not on this page.', $span->month(new Request('GET', '/tax', ['extra' => '1'], []), '2026-11-20')->error);
        self::assertSame('That filter is not on this page.', $span->month(new Request('GET', '/tax', ['print' => '2'], []), '2026-11-20')->error);
        self::assertSame('November 2026', $span->month(new Request('GET', '/tax', [], []), '2026-11-20')->label);
        self::assertSame('That filter is not on this page.', $span->pinned(new Request('GET', '/rent', ['extra' => '1'], []), '2026-11-20')->error);
    }

    public function testABookRefusalAndABadAmountStayOffTheSheet(): void
    {
        $rows = new BranchRows();
        $rows->refusal = new DatabaseRefusal('Sales tax does not match the books.', 0, null, '23514');
        $statements = new BranchStatements($this->createStub(PDO::class), $rows);
        $fetch = new ReportFetch(new LedgerReport($statements), new ReportTie($statements));
        $sales = $fetch->sales(new Request('GET', '/sales', ['range' => 'today'], []), '2026-11-20');
        self::assertSame('Sales tax does not match the books.', $sales->data['error'] ?? null);
        $payouts = $fetch->payouts(new Request('GET', '/payouts', ['range' => 'today'], []), '2026-11-20');
        self::assertSame('Sales tax does not match the books.', $payouts->data['error'] ?? null);
        $rent = $fetch->rent(new Request('GET', '/rent', [], []), '2026-11-20');
        self::assertSame('The rent roll does not match the books.', $rent->data['error'] ?? null);
        $pinned = $fetch->rent(new Request('GET', '/rent', ['extra' => '1'], []), '2026-11-20');
        self::assertSame('That filter is not on this page.', $pinned->data['error'] ?? null);

        $other = new BranchRows();
        $other->refusal = new DatabaseRefusal('the books refused', 0, null, 'P0001');
        $tie = new ReportTie(new BranchStatements($this->createStub(PDO::class), $other));
        try {
            $tie->tax('2026-11-01', '2026-11-30');
            self::fail('a foreign refusal was rewritten');
        } catch (DatabaseRefusal $error) {
            self::assertSame('P0001', $error->sqlState());
        }

        $tax = new SalesTaxReport(new SalesTaxSheet($statements), new SalesTaxFiling(), new ReportTie($statements));
        $page = $tax->page(new Request('GET', '/tax', ['month' => '2026-11'], []), '2026-11-20');
        self::assertSame('Sales tax does not match the books.', $page->data['error'] ?? null);
        $typed = $tax->page(new Request('GET', '/tax', ['month' => 'June'], []), '2026-11-20');
        self::assertSame('June', $typed->data['month'] ?? null);
        $blank = $tax->page(new Request('GET', '/tax', ['extra' => '1'], []), '2026-11-20');
        self::assertSame('2026-11', $blank->data['month'] ?? null);
        self::assertSame('That filter is not on this page.', $blank->data['error'] ?? null);

        $table = new ReportTable();
        try {
            $table->cells([new ReportColumn('amount', 'Amount', 'money')], [['amount' => '12.00']]);
            self::fail('a text amount was totaled');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('That amount is not on this page.', $error->getMessage());
        }
    }

    public function testFilingDetailNamesRatesAndLiability(): void
    {
        $detail = (new SalesTaxFiling())->detail([
            [
                'section' => 'rate',
                'label' => '8000',
                'sale_count' => 1,
                'items_sold' => 1,
                'subtotal_minor' => 100,
                'discount_minor' => 0,
                'tax_minor' => 8,
                'total_minor' => 108,
            ],
            [
                'section' => 'liability',
                'label' => 'Opening',
                'sale_count' => 1,
                'items_sold' => 0,
                'subtotal_minor' => 0,
                'discount_minor' => 0,
                'tax_minor' => 0,
                'total_minor' => 40,
            ],
            [
                'section' => 'taxable',
                'label' => 'Taxed',
                'sale_count' => 0,
                'items_sold' => 0,
                'subtotal_minor' => 0,
                'discount_minor' => 0,
                'tax_minor' => 0,
                'total_minor' => 0,
            ],
        ]);
        self::assertSame('Rate', $detail[0]['section']);
        self::assertSame('8.00%', $detail[0]['label']);
        self::assertSame('Started the month', $detail[1]['label']);
        self::assertCount(2, $detail);
        $saved = new SavedReport(new BranchStatements($this->createStub(PDO::class), new BranchRows()), new ReportCatalog());
        self::assertSame('/reports/donated?range=today', $saved->path(['sheet' => 'donated', 'grouped' => 'none', 'range_key' => 'today']));
        self::assertSame('/reports/sell-through?range=today', $saved->path(['sheet' => 'sell_through', 'grouped' => 'none', 'range_key' => 'today']));
        try {
            $saved->path(['sheet' => 'missing', 'grouped' => 'none', 'range_key' => 'today']);
            self::fail('a missing sheet was linked');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('That report is not on this page.', $error->getMessage());
        }
        try {
            (new ReportCatalog())->accept('Hourly', 'sales', 'hour', 'yesterday');
            self::fail('an hourly report covered more than one day');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('An hourly report is one day.', $error->getMessage());
        }
    }

    public function testSavingAReportRefusesADuplicateAndAMissingName(): void
    {
        $door = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/reports/build', [], [
            'csrf' => 'csrf-token',
            'name' => '',
            'sheet' => 'sales',
            'grouped' => 'day',
            'range_key' => 'today',
        ]));
        $html = $this->capture(function () use ($door): void {
            $this->reports($door, new BranchRows())->store(MallDoor::SLUG);
        });
        self::assertStringContainsString('name', strtolower($html));

        $duplicate = new BranchRows();
        $duplicate->refusal = new DatabaseRefusal('duplicate', 0, null, '23505');
        $duplicate->refusalSql = 'INSERT INTO saved_reports';
        $named = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/reports/build', [], [
            'csrf' => 'csrf-token',
            'name' => 'Daily',
            'sheet' => 'sales',
            'grouped' => 'day',
            'range_key' => 'today',
        ]));
        $again = $this->capture(function () use ($named, $duplicate): void {
            $this->reports($named, $duplicate)->store(MallDoor::SLUG);
        });
        self::assertStringContainsString('That name is already used.', $again);

        $foreign = new BranchRows();
        $foreign->refusal = new DatabaseRefusal('the books refused', 0, null, 'P0001');
        $foreign->refusalSql = 'INSERT INTO saved_reports';
        $third = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/reports/build', [], [
            'csrf' => 'csrf-token',
            'name' => 'Daily',
            'sheet' => 'sales',
            'grouped' => 'day',
            'range_key' => 'today',
        ]));
        $refused = $this->capture(function () use ($third, $foreign): void {
            $this->reports($third, $foreign)->store(MallDoor::SLUG);
        });
        self::assertStringContainsString('the books refused', $refused);

        $missing = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/reports/saved/' . MallDoor::ACCOUNT . '/delete', [], [
            'csrf' => 'csrf-token',
        ]));
        $this->capture(function () use ($missing): void {
            $this->reports($missing, new BranchRows())->deleteSaved(MallDoor::SLUG, MallDoor::ACCOUNT);
        });
        $found = new BranchRows();
        $found->bound = [[
            'id' => MallDoor::ACCOUNT,
            'name' => 'Daily',
            'sheet' => 'sales',
            'grouped' => 'day',
            'range_key' => 'today',
        ]];
        $present = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/reports/saved/' . MallDoor::ACCOUNT . '/delete', [], [
            'csrf' => 'csrf-token',
        ]));
        $this->capture(function () use ($present, $found): void {
            $this->reports($present, $found)->deleteSaved(MallDoor::SLUG, MallDoor::ACCOUNT);
        });
    }

    public function testActivityFiltersCursorsAndACustomerLink(): void
    {
        self::assertSame(1, ActivityPhrase::order(
            ['code' => 'b', 'phrase' => 'Same'],
            ['code' => 'a', 'phrase' => 'Same'],
        ));
        $badDate = ActivityFilter::fromRequest(new Request('GET', '/activity', ['from' => 'nope'], []));
        self::assertSame('Enter a real date.', $badDate->error);
        $badPerson = ActivityFilter::fromRequest(new Request('GET', '/activity', ['account' => 'nope'], []));
        self::assertSame('Choose a person.', $badPerson->error);
        $badAction = ActivityFilter::fromRequest(new Request('GET', '/activity', ['action' => 'nope'], []));
        self::assertSame('That action is not a filter.', $badAction->error);
        self::assertSame('a', ActivityCursor::newer('after', [['id' => 'a']], true, null));
        self::assertNull(ActivityCursor::newer('before', [], false, null));

        $id = MallDoor::ACCOUNT;
        $rows = new BranchRows();
        $statements = new BranchStatements($this->createStub(PDO::class), $rows);
        $linked = (new ActivityHref($statements))->apply(MallDoor::SLUG, 'owner', 'vendors', [[
            'id' => $id,
            'at' => '2026-11-01 00:00:00+00',
            'username' => 'ada',
            'name' => 'Ada',
            'role' => 'owner',
            'action' => 'customer.edit',
            'entity_type' => 'customer',
            'entity_id' => $id,
            'entity' => 'Ada',
            'authority' => '',
            'entered_by' => '',
            'address' => '',
            'recorded' => true,
        ]]);
        self::assertSame('/' . MallDoor::SLUG . '/customers/' . $id, $linked[0]['href']);

        $rows->bound = [['at' => new DateTimeImmutable('2026-11-01 00:00:00+00'), 'id' => $id]];
        $page = (new ActivityLog($statements))->page(new ActivityFilter(null, null, null, null, $id, null, null), 50);
        self::assertIsArray($page);

        $count = new BranchRows();
        $count->bound = [['total' => 5001]];
        $door = MallDoor::open($this, new Request('GET', '/' . MallDoor::SLUG . '/activity.csv', [], []));
        $people = new ActivityPeople(
            $door->statements,
            new MallOwners($door->pdo, $door->roles, new RecoveryAddress(), new PasswordChoice()),
        );
        $controller = new ActivityController(
            $door->app,
            new ActivityLog(new BranchStatements($this->createStub(PDO::class), $count)),
            $people,
            new ActivityHref($door->statements),
            $door->actor,
            $door->mall,
        );
        $html = $this->capture(static function () use ($controller): void {
            $controller->csv(MallDoor::SLUG);
        });
        self::assertStringContainsString('Narrow the dates.', $html);
    }

    public function testActivityAndTaxTemplatesShowTheEmptyAndNamedBranches(): void
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        $filter = new ActivityFilter(null, null, null, null, null, null, null);
        $open = $this->render($view, 'Feature/Activity/activity', $this->activityData($filter, [], true));
        self::assertStringContainsString('Sales, voids, staff changes', $open);
        $cursor = new ActivityFilter(null, null, null, null, MallDoor::ACCOUNT, null, null);
        $page = $this->render($view, 'Feature/Activity/activity', $this->activityData($cursor, [], false));
        self::assertStringContainsString('No rows on this page.', $page);
        $row = [
            'id' => MallDoor::ACCOUNT,
            'at' => '2026-11-01 00:00:00+00',
            'username' => 'ada',
            'name' => '',
            'role' => 'manager',
            'action' => 'item.edit',
            'entity_type' => 'item',
            'entity_id' => MallDoor::ACCOUNT,
            'entity' => 'Lamp',
            'authority' => 'Bea',
            'entered_by' => 'platform@example.test',
            'address' => '127.0.0.1',
            'recorded' => true,
            'href' => '',
        ];
        $books = $row;
        $books['role'] = 'books';
        $other = $row;
        $other['role'] = 'floor';
        $listed = $this->render($view, 'Feature/Activity/activity', $this->activityData($filter, [$row, $books, $other], false));
        self::assertStringContainsString('Manager', $listed);
        self::assertStringContainsString('Books', $listed);
        self::assertStringContainsString('floor', $listed);
        self::assertStringContainsString('Second signer', $listed);
        self::assertStringContainsString('Entered as', $listed);

        $tax = $this->render($view, 'Feature/Report/sales-tax', [
            'slug' => MallDoor::SLUG,
            'label' => 'November 2026',
            'month' => '2026-11',
            'print' => false,
            'error' => null,
            'filing' => ['gross' => 100, 'exempt' => 0, 'returns' => 0, 'taxable' => 100, 'collected' => 8],
            'rates' => [['label' => '8.00%', 'base_minor' => 100]],
            'liability' => ['opening' => 0, 'collected' => 8, 'paid' => 0, 'closing' => 8],
            'headers' => [],
            'lines' => [],
            'download' => 'sales-tax.csv',
        ]);
        self::assertStringContainsString('8.00%', $tax);
        $sales = $this->render($view, 'Feature/Vendor/sales', [
            'slug' => MallDoor::SLUG,
            'span' => ['from' => '2026-11-20', 'to' => '2026-11-01', 'label' => 'Custom'],
            'rows' => [],
            'total' => 0,
            'previewAs' => '',
        ]);
        self::assertStringContainsString('Custom', $sales);
        $verify = $this->render($view, 'Feature/Signup/verify', [
            'csrf' => 'csrf-token',
            'token' => str_repeat('a', 43),
            'email' => 'ada@example.test',
        ]);
        self::assertStringContainsString('set up the store for ada@example.test', $verify);
        try {
            $view->render('Feature/Admin/layout', [
                'title' => 'Admin',
                'csrf' => 'csrf-token',
                'body' => 'Feature/Admin/login',
                'templateDirectory' => dirname(__DIR__, 3) . '/src',
            ]);
            self::fail('a layout without the admin path rendered');
        } catch (RuntimeException $error) {
            self::assertSame('admin path was not passed to the layout', $error->getMessage());
        }
        $worker = $this->render($view, 'Feature/Admin/worker', [
            'csrf' => 'csrf-token',
            'adminPath' => new AdminPath(AdminDoor::prefix()),
            'status' => [
                'password_configured' => true,
                'last_seen_at' => '2026-11-20',
                'last_worker' => 'worker',
                'worker_stale' => false,
                'last_jobs_finished' => 1,
                'last_jobs_failed' => 0,
                'waiting_mail' => 0,
                'overdue_jobs' => 0,
                'auto_prune_days' => 30,
                'site_smtp' => true,
                'platform_gmail' => true,
            ],
            'queue' => [],
            'givenUp' => [[
                'tenant' => 'gapmall',
                'kind' => 'sale-notice',
                'finished_at' => '2026-11-20',
                'attempts' => 5,
                'subject' => 'Sale',
                'recipients' => 'ada@example.test',
                'transport' => 'site SMTP',
                'error' => 'down',
            ]],
            'runs' => [],
        ]);
        self::assertStringContainsString('sale-notice', $worker);
    }

    public function testAnEmptyAuditAndABadSignerAreRefused(): void
    {
        try {
            new \PerfectConsign\Bootstrap\AuditAuthority('not-a-person', 'ada');
            self::fail('a bad signer was stored');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('a second signer is a different person', $error->getMessage());
        }
        try {
            new \PerfectConsign\Bootstrap\AuditAuthority(MallDoor::ACCOUNT, 'Ada');
            self::fail('a display name was used as a username');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('a second signer is a different person', $error->getMessage());
        }
        $rows = new BranchRows();
        $rows->affected = 0;
        $log = new AuditLog(
            new BranchStatements($this->createStub(PDO::class), $rows),
            new Request('POST', '/', [], [], '', '', '127.0.0.1'),
            new RequestId('test-request'),
        );
        $session = new BagSession([
            'actor_role' => 'owner',
            'actor_username' => 'ada',
            'actor_account' => MallDoor::ACCOUNT,
            'owner_name' => 'ada',
            AdminSession::ID => 'not-a-uuid',
            AdminSession::EMAIL => 'ada@example.test',
            AdminSession::VERSION => '1',
        ]);
        try {
            $log->record(AuditSubject::capture(new Actor($session), new AdminSession($session), '127.0.0.1'), 'settings.edit', 'company', MallDoor::ACCOUNT);
            self::fail('a bad operator was stored');
        } catch (DatabaseRefusal $error) {
            self::assertSame('the signed-in account is missing', $error->getMessage());
        }
        try {
            $log->record($this->subject(), '', 'company', MallDoor::ACCOUNT);
            self::fail('an empty action was stored');
        } catch (DatabaseRefusal $error) {
            self::assertSame('the audit row is incomplete', $error->getMessage());
        }
        try {
            $log->record($this->subject(), 'settings.edit', 'company', MallDoor::ACCOUNT);
            self::fail('a missing audit row was accepted');
        } catch (DatabaseRefusal $error) {
            self::assertSame('the audit row was not stored', $error->getMessage());
        }
        try {
            new RequestId('');
            self::fail('an empty request id was kept');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('the request id is not usable', $error->getMessage());
        }

    }

    public function testJobAttentionNamesEachTransportAndAFailedReportIsLogged(): void
    {
        $saved = getenv('STALL_PLATFORM_ADMIN_EMAIL');
        putenv('STALL_PLATFORM_ADMIN_EMAIL=');
        try {
            $bucket = '';
            $left = [];
            $pdo = $this->createStub(PDO::class);
            $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$bucket, &$left): PDOStatement {
                $statement = $this->createStub(PDOStatement::class);
                $statement->method('execute')->willReturnCallback(function (array $params) use ($sql, &$bucket, &$left): bool {
                    if (str_contains($sql, 'job_attention')) {
                        $name = $params[0] ?? '';
                        $bucket = is_string($name) ? $name : '';
                        $left = $bucket === 'overdue' ? [] : $this->attentionRows();
                    }

                    return true;
                });
                $statement->method('fetch')->willReturnCallback(function () use (&$left): array|false {
                    $next = array_shift($left);

                    return is_array($next) ? $next : false;
                });

                return $statement;
            });
            $logger = new NullLogger();
            $mail = new OutboundMail($logger);
            $notice = new PlatformOperatorNotice($logger);
            $attention = new JobAttention($pdo, $mail, new PlatformMailbox($mail, new GmailSender($notice), $notice), $notice);
            $attention->report();
            $open = $attention->givenUp();
            self::assertSame('2026-11-01', $open[0]['subject']);
            self::assertNotSame('', $open[1]['transport']);
        } finally {
            if (!is_string($saved) || $saved === '') {
                putenv('STALL_PLATFORM_ADMIN_EMAIL');
            } else {
                putenv('STALL_PLATFORM_ADMIN_EMAIL=' . $saved);
            }
        }

        $failing = $this->createStub(PDO::class);
        $failing->method('prepare')->willThrowException(new RuntimeException('attention down'));
        $runner = (new ReflectionClass(JobRunner::class))->newInstanceWithoutConstructor();
        $logged = $this->createMock(LoggerInterface::class);
        $logged->expects($this->once())->method('error')->with('job attention was not sent');
        (new ReflectionProperty(JobRunner::class, 'attention'))->setValue($runner, new JobAttention(
            $failing,
            new OutboundMail(new NullLogger()),
            new PlatformMailbox(new OutboundMail(new NullLogger()), new GmailSender(new PlatformOperatorNotice(new NullLogger())), new PlatformOperatorNotice(new NullLogger())),
            new PlatformOperatorNotice(new NullLogger()),
        ));
        (new ReflectionProperty(JobRunner::class, 'logger'))->setValue($runner, $logged);
        (new ReflectionMethod(JobRunner::class, 'reportAttention'))->invoke($runner);
    }

    public function testAClosedSocketAndAnUnstoredDealAreRefused(): void
    {
        $client = (new ReflectionClass(SmtpClient::class))->newInstanceWithoutConstructor();
        $socket = fopen('php://temp', 'rb');
        self::assertIsResource($socket);
        try {
            (new ReflectionMethod(SmtpClient::class, 'read'))->invoke($client, $socket);
            self::fail('a closed socket returned a reply');
        } catch (RuntimeException $error) {
            self::assertSame('smtp send failed', $error->getMessage());
        } finally {
            fclose($socket);
        }

        $streak = $this->createStub(PDO::class);
        $streak->method('prepare')->willReturnCallback(function (): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('execute')->willReturn(true);
            $statement->method('fetchColumn')->willReturn('true');

            return $statement;
        });
        self::assertTrue((new ProbeBanStore($streak))->hit('203.0.113.10', 'browser', '/x.php'));
        $broken = $this->createStub(PDO::class);
        $broken->method('prepare')->willReturnCallback(function (): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('execute')->willReturn(true);
            $statement->method('fetchColumn')->willReturn(false);

            return $statement;
        });
        $broken->method('query')->willReturn(false);
        $bans = new ProbeBanStore($broken);
        try {
            $bans->hit('203.0.113.11', 'browser', '/x.php');
            self::fail('a missing hit result continued');
        } catch (RuntimeException $error) {
            self::assertSame('the probe hit was not stored', $error->getMessage());
        }
        try {
            $bans->insertBan('203.0.113.11', 'streak', 'browser', '/x.php');
            self::fail('a missing ban result continued');
        } catch (RuntimeException $error) {
            self::assertSame('the probe ban was not stored', $error->getMessage());
        }
        try {
            $bans->listBans();
            self::fail('a failed ban list continued');
        } catch (RuntimeException $error) {
            self::assertSame('probe bans could not be listed', $error->getMessage());
        }

        $schema = 'tenant_' . str_repeat('ab', 16);
        $demo = $this->createStub(PDO::class);
        $demoReads = 0;
        $demo->method('prepare')->willReturnCallback(function () use (&$demoReads): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('execute')->willReturn(true);
            $statement->method('fetch')->willReturnCallback(function () use (&$demoReads): array {
                $demoReads++;
                if ($demoReads === 1) {
                    return ['id' => 'nope', 'username' => 'ada'];
                }

                return ['id' => MallDoor::ACCOUNT, 'username' => 'Ada'];
            });

            return $statement;
        });
        try {
            DemoSubject::stamp($demo, 'public', new BagSession());
            self::fail('a public schema was stamped');
        } catch (RuntimeException $error) {
            self::assertSame('the demo owner is missing', $error->getMessage());
        }
        try {
            DemoSubject::stamp($demo, $schema, new BagSession());
            self::fail('an owner id was missing');
        } catch (RuntimeException $error) {
            self::assertSame('the demo owner is missing', $error->getMessage());
        }
        try {
            DemoSubject::stamp($demo, $schema, new BagSession());
            self::fail('a display name was stamped');
        } catch (RuntimeException $error) {
            self::assertSame('the demo owner is missing', $error->getMessage());
        }

        $offers = new OfferStore(new BranchStatements($this->createStub(PDO::class), new BranchRows()));
        try {
            $offers->save(null, [
                'name' => 'Two for one',
                'scope' => 'store',
                'kind' => 'bundle',
                'threshold' => '2',
                'price' => '10.00',
                'funded_by' => 'store',
                'starts_on' => '2026-11-01',
                'ends_on' => '',
                'item_code' => '',
                'vendor_id' => '',
            ], null);
            self::fail('a missing deal id was returned');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('That deal was not saved.', $error->getMessage());
        }
    }

    public function testAnEmptyPostedJournalIsRefused(): void
    {
        $door = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/close', [], [
            'csrf' => 'csrf-token',
            'period_id' => MallDoor::ACCOUNT,
            'ends_on' => '2026-12-31',
        ]));
        $rows = new BranchRows();
        $rows->bound = [['ends_on' => '2026-12-31']];
        $rows->listed = [['account_id' => MallDoor::ACCOUNT, 'net_minor' => 10]];
        $statements = new BranchStatements($this->createStub(PDO::class), $rows);
        $empty = true;
        $ledger = $this->ledger($empty);
        $close = new CloseController(
            $door->app,
            $ledger,
            new CloseStore($door->pdo, $statements),
            $door->mall,
            QuietAudit::journal($statements, $door->actor),
        );
        $html = $this->capture(static function () use ($close): void {
            $close->submit(MallDoor::SLUG);
        });
        self::assertStringContainsString('the period was not closed', $html);
        $empty = false;
        $this->capture(static function () use ($close): void {
            $close->submit(MallDoor::SLUG);
        });

        $taxDoor = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/tax', [], [
            'csrf' => 'csrf-token',
            'source_reference' => 'ST-1',
            'amount' => '10.00',
            'credit' => 'bank',
            'posting_date' => '2026-11-20',
        ]));
        $taxEmpty = true;
        $taxLedger = $this->ledger($taxEmpty);
        $taxQuery = $this->createStub(PDOStatement::class);
        $taxQuery->method('fetchColumn')->willReturn(0);
        $taxPdo = $this->createStub(PDO::class);
        $taxPdo->method('query')->willReturn($taxQuery);
        $tax = new TaxController($taxDoor->app, $taxLedger, new TaxStore($taxPdo), QuietAudit::journal($statements, $taxDoor->actor));
        $taxPage = $this->capture(static function () use ($tax): void {
            $tax->submit(MallDoor::SLUG);
        });
        self::assertStringContainsString('the tax payment was not posted', $taxPage);

        $asked = [];
        $postings = $this->createStub(\PerfectConsign\Ledger\PostingFunctions::class);
        $postings->method('post')->willReturnCallback(
            static function (string $date, string $call, array $values, ?string $session = null, ?string $check = null, ?int $tendered = null, ?callable $beforeCommit = null) use (&$asked): string {
                $asked = [$date, $call, $values];
                if ($beforeCommit !== null) {
                    $beforeCommit(MallDoor::JOURNAL);
                }

                return MallDoor::JOURNAL;
            }
        );
        $correction = new BoothCorrection(
            $postings,
            new SaleCeiling($statements, $door->actor, QuietAudit::journal($statements, $door->actor), new Request('POST', '/', [], [], '', '', '127.0.0.1')),
        );
        self::assertSame(MallDoor::JOURNAL, $correction->move('2026-11-20', MallDoor::JOURNAL, MallDoor::ACCOUNT, MallDoor::PERSON));
        self::assertSame(
            ['2026-11-20', 'stall_booth_correction(?::uuid, ?::uuid, ?::uuid, ?::date)', [MallDoor::JOURNAL, MallDoor::ACCOUNT, MallDoor::PERSON, '2026-11-20']],
            $asked,
        );

        $register = new RegisterSession(
            new BranchStatements($this->createStub(PDO::class), $this->openRegister()),
            $this->ledger($taxEmpty),
            new MallClock($door->mall),
            new RegisterActivity($statements, new MallClock($door->mall)),
            QuietAudit::journal($statements, $door->actor),
        );
        try {
            $register->drop(MallDoor::DRAWER, 500, '2026-11-20');
            self::fail('an empty drop journal was kept');
        } catch (DatabaseRefusal $error) {
            self::assertSame('the register did not drop', $error->getMessage());
        }
    }

    public function testAStaffInsertAndAnOwnerSavepointAreRefused(): void
    {
        $store = new StaffStore(new BranchStatements($this->createStub(PDO::class), new BranchRows()), new RecoveryAddress(), new PasswordChoice());
        try {
            $store->addCashier('ada-lane', 'Ada Lane', 'password1', 'ada@example.test');
            self::fail('a missing staff id was stored');
        } catch (RuntimeException $error) {
            self::assertSame('the staff row was not stored', $error->getMessage());
        }

        $controller = (new ReflectionClass(StaffController::class))->newInstanceWithoutConstructor();
        $statements = new BranchStatements($this->createStub(PDO::class), new BranchRows());
        (new ReflectionProperty(StaffController::class, 'audit'))->setValue($controller, QuietAudit::make($statements));
        try {
            (new ReflectionMethod(StaffController::class, 'created'))->invoke($controller, 'staff.create', static fn (): string => '');
            self::fail('an empty staff id was audited');
        } catch (RuntimeException $error) {
            self::assertSame('the staff row was not stored', $error->getMessage());
        }

        $schema = 'tenant_' . str_repeat('ab', 16);
        $tenant = new TenantRecord(MallDoor::TENANT, MallDoor::SLUG, $schema, TenantRecord::roleFor(MallDoor::SLUG, $schema), 'Gap');
        $owners = new MallOwners($this->ownerPdo(false, 0, 0, 0, 'nope'), new DatabaseRole($this->ownerPdo(false, 0, 0, 0, 'nope')), new RecoveryAddress(), new PasswordChoice());
        try {
            $owners->add($tenant, 'ada', 'password1', 'ada@example.test');
            self::fail('a missing owner id was stored');
        } catch (RuntimeException $error) {
            self::assertSame('the owner row was not stored', $error->getMessage());
        }

        $this->expectOwner($tenant, true, 1, 0, 0, MallDoor::ACCOUNT, null, 'the owner change could not start');
        $this->expectOwner($tenant, true, 0, 0, 1, MallDoor::ACCOUNT, null, 'the owner change could not finish');
        $this->expectOwner($tenant, true, 0, 1, 0, MallDoor::ACCOUNT, new \PDOException('down'), 'the owner change could not be undone');
        $this->expectOwner($tenant, true, 0, 0, 1, MallDoor::ACCOUNT, new \PDOException('down'), 'the owner change could not be undone');
    }

    private function expectOwner(
        TenantRecord $tenant,
        bool $open,
        int $savepoint,
        int $rollback,
        int $release,
        string $id,
        ?\Throwable $boom,
        string $message,
    ): void {
        $owners = new MallOwners(
            $this->ownerPdo($open, $savepoint, $rollback, $release, $id, $boom),
            new DatabaseRole($this->ownerPdo(false, 0, 0, 0, $id)),
            new RecoveryAddress(),
            new PasswordChoice(),
        );
        try {
            $owners->add($tenant, 'ada', 'password1', 'ada@example.test');
            self::fail('an owner savepoint was ignored');
        } catch (RuntimeException $error) {
            self::assertSame($message, $error->getMessage());
        }
    }

    private function reports(MallDoor $door, BranchRows $rows): ReportsController
    {
        $statements = new BranchStatements($this->createStub(PDO::class), $rows);
        $fetch = new ReportFetch(new LedgerReport($statements), new ReportTie($statements));

        return new ReportsController(
            $door->app,
            $door->actor,
            $door->mall,
            $fetch,
            new SalesTaxReport(new SalesTaxSheet($statements), new SalesTaxFiling(), new ReportTie($statements)),
            new SavedReport($statements, new ReportCatalog()),
        );
    }

    private function subject(): AuditSubject
    {
        $session = new BagSession([
            'actor_role' => 'owner',
            'actor_username' => 'ada',
            'actor_account' => MallDoor::ACCOUNT,
            'owner_name' => 'ada',
        ]);

        return AuditSubject::capture(new Actor($session), new AdminSession($session), '127.0.0.1');
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function activityData(ActivityFilter $filter, array $rows, bool $open): array
    {
        return [
            'filter' => $open ? $filter : new ActivityFilter(null, null, null, null, null, MallDoor::ACCOUNT, null),
            'rows' => $rows,
            'error' => null,
            'slug' => MallDoor::SLUG,
            'export' => '',
            'people' => ['current' => [], 'former' => []],
            'actions' => [],
            'older' => null,
            'newer' => null,
            'total' => 0,
            'seller' => 'vendor',
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(View $view, string $template, array $data): string
    {
        ob_start();
        try {
            $view->render($template, $data);
        } catch (\Throwable $error) {
            ob_end_clean();
            throw $error;
        }
        $html = ob_get_clean();
        self::assertIsString($html);

        return $html;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function attentionRows(): array
    {
        $id = '01900000-0000-7000-8000-0000000000aa';

        return [
            $this->attentionRow($id, 'sale-notice', '', '2026-11-01', '', ''),
            $this->attentionRow($id, 'outbound-mail', 'Reset', '', 'platform', ''),
            $this->attentionRow($id, 'vendor-letter', 'Letter', '', 'mall', 'gmail'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attentionRow(string $id, string $kind, string $subject, string $date, string $mailbox, string $via): array
    {
        return [
            'id' => $id,
            'tenant' => 'gapmall',
            'kind' => $kind,
            'subject' => $subject,
            'payload_date' => $date,
            'mailbox' => $mailbox,
            'mail_via' => $via,
            'attempts' => 1,
            'error' => 'down',
            'recipients' => 'ada@example.test',
            'run_at' => '2026-11-20',
            'finished_at' => '2026-11-20',
            'due' => true,
        ];
    }

    private function ledger(bool &$empty): LedgerStore&Stub
    {
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('roleAccount')->willReturn(MallDoor::ACCOUNT);
        $ledger->method('newId')->willReturn(MallDoor::JOURNAL);
        $ledger->method('periods')->willReturn([]);
        $ledger->method('post')->willReturnCallback(function (JournalDraft $draft) use (&$empty): string {
            $hook = $draft->beforeCommit;
            if ($hook !== null) {
                $hook($empty ? '' : MallDoor::JOURNAL);
            }

            return MallDoor::JOURNAL;
        });

        return $ledger;
    }

    private function openRegister(): BranchRows
    {
        $rows = new BranchRows();
        $rows->bound = [[
            'id' => MallDoor::DRAWER,
            'opened_at' => '',
        ]];

        return $rows;
    }

    private function ownerPdo(
        bool $open,
        int $savepoint,
        int $rollback,
        int $release,
        string $id,
        ?\Throwable $boom = null,
    ): PDO&Stub {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('inTransaction')->willReturn($open);
        $pdo->method('exec')->willReturnCallback(static function (string $sql) use ($savepoint, $rollback, $release): int|false {
            if (str_contains($sql, 'ROLLBACK TO')) {
                return $rollback === 0 ? 0 : false;
            }
            if (str_contains($sql, 'RELEASE')) {
                return $release === 0 ? 0 : false;
            }
            if (str_contains($sql, 'SAVEPOINT')) {
                return $savepoint === 0 ? 0 : false;
            }

            return 0;
        });
        $pdo->method('prepare')->willReturnCallback(function () use ($id, $boom): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('execute')->willReturnCallback(static function () use ($boom): bool {
                if ($boom !== null) {
                    throw $boom;
                }

                return true;
            });
            $statement->method('fetchColumn')->willReturn($id);

            return $statement;
        });

        return $pdo;
    }
}
