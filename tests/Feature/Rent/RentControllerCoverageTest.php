<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Rent;

use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\ActiveRegister;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\AuditLog;
use PerfectConsign\Bootstrap\CredentialStamp;
use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Bootstrap\FrontDoor;
use PerfectConsign\Bootstrap\JournalAudit;
use PerfectConsign\Bootstrap\LoginSession;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Bootstrap\MissingPage;
use PerfectConsign\Bootstrap\OwnerGate;
use PerfectConsign\Bootstrap\PostedRefusal;
use PerfectConsign\Bootstrap\PriorClose;
use PerfectConsign\Bootstrap\SaleCeiling;
use PerfectConsign\Bootstrap\SignInFailureRetention;
use PerfectConsign\Bootstrap\StaffSignIn;
use PerfectConsign\Bootstrap\TenantLookup;
use PerfectConsign\Feature\Company\CompanyLines;
use PerfectConsign\Feature\Company\CompanyStore;
use PerfectConsign\Feature\Mall\MallClock;
use PerfectConsign\Feature\Mall\MallDay;
use PerfectConsign\Feature\Mall\MallPayoutSettings;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Feature\Payout\OccupiedPack;
use PerfectConsign\Feature\Payout\PayoutRunLines;
use PerfectConsign\Feature\Payout\PayoutRunStore;
use PerfectConsign\Feature\Payout\PayoutSettler;
use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Feature\Payout\PayoutWindow;
use PerfectConsign\Feature\Payout\VendorClawback;
use PerfectConsign\Feature\Register\RegisterActivity;
use PerfectConsign\Feature\Register\RegisterStore;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Feature\Rent\LateFee;
use PerfectConsign\Feature\Rent\LateFeeDue;
use PerfectConsign\Feature\Rent\RentBook;
use PerfectConsign\Feature\Rent\RentController;
use PerfectConsign\Feature\Rent\RentLines;
use PerfectConsign\Feature\Rent\RentPayment;
use PerfectConsign\Feature\Rent\RentPosting;
use PerfectConsign\Feature\Rent\RentReceipt;
use PerfectConsign\Feature\Rent\RentSlip;
use PerfectConsign\Feature\Rent\RentStore;
use PerfectConsign\Ledger\JournalCash;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Auth\OwnerAccess;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\ResponseSent;
use PerfectConsign\Shared\Http\View;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RentControllerCoverageTest extends TestCase
{
    private const SLUG = 'zcov';

    private const VENDOR = '01900000-0000-7000-8000-0000000000a1';

    private const BOOTH = '01900000-0000-7000-8000-0000000000b1';

    private const FEE = '01900000-0000-7000-8000-0000000000d1';

    private const DRAWER = '01900000-0000-7000-8000-0000000000e1';

    private const ACCOUNT = '01900000-0000-7000-8000-0000000000aa';

    private bool $prepaid = false;

    private bool $cover = false;

    private bool $fees = false;

    private bool $refusePost = false;

    public static string $location = '';

    private bool $slip = false;

    private bool $drawer = false;

    private int $figuresLeft = 0;

    /** @var array<string, mixed> */
    private array $sessionBag = [];

    public function testTheRentBookRendersAndAChargeCanBeSavedOrRefused(): void
    {
        $html = $this->capture(function (): void {
            $this->controller($this->request('GET', '/zcov/rent', ['month' => '2026-06', 'q' => ''], []))->form(self::SLUG);
        });
        self::assertStringContainsString('Rent', $html);

        $this->prepaid = true;
        $prepaid = $this->capture(function (): void {
            $this->controller($this->request('POST', '/zcov/rent/charge', [], [
                'booth_id' => self::BOOTH,
                'posting_date' => '2026-06-15',
            ]))->charge(self::SLUG);
        });
        self::assertStringContainsString('this rent comes out of sales', $prepaid);
        $this->prepaid = false;

        $this->redirect(function (): void {
            $this->cover = true;
            $this->controller($this->request('POST', '/zcov/rent/charge', [], [
                'booth_id' => self::BOOTH,
                'posting_date' => '2026-06-15',
                'month' => '2026-06',
            ]))->charge(self::SLUG);
        }, '/zcov/rent?month=2026-06');

        $this->redirect(function (): void {
            $this->cover = true;
            $this->controller($this->request('POST', '/zcov/rent/charge', [], [
                'booth_id' => self::BOOTH,
                'posting_date' => '2026-06-15',
            ]))->charge(self::SLUG);
        }, '/zcov/rent?month=2026-06');

        $this->cover = false;
        $refused = $this->capture(function (): void {
            $this->controller($this->request('POST', '/zcov/rent/charge', [], [
                'booth_id' => self::BOOTH,
                'posting_date' => '2026-06-15',
            ]))->charge(self::SLUG);
        });
        self::assertNotSame('', $refused);
    }

    public function testCollectionShowsAMissingPayerABadAmountAndAReceipt(): void
    {
        $choose = $this->capture(function (): void {
            $this->controller($this->request('GET', '/zcov/rent/collect', [], []))->collectForm(self::SLUG);
        });
        self::assertStringContainsString('Choose who is paying rent.', $choose);

        $none = $this->capture(function (): void {
            $this->controller($this->request('GET', '/zcov/rent/collect', [], [
                'vendor_id' => self::VENDOR,
                'booth_id' => self::BOOTH,
            ]))->collectForm(self::SLUG);
        });
        self::assertStringContainsString('They do not owe rent.', $none);

        $this->figuresLeft = 4;
        $this->drawer = true;
        $bad = $this->capture(function (): void {
            $this->controller($this->request('POST', '/zcov/rent/payment', ['vendor' => self::VENDOR, 'booth' => self::BOOTH], [
                'vendor_id' => self::VENDOR,
                'booth_id' => self::BOOTH,
                'amount' => 'nope',
            ]))->payment(self::SLUG);
        });
        self::assertStringContainsString('enter an amount in dollars and cents', $bad);

        $this->figuresLeft = 1;
        $waived = $this->redirect(function (): void {
            $this->fees = true;
            $this->controller($this->request('POST', '/zcov/rent/payment', [], [
                'vendor_id' => self::VENDOR,
                'booth_id' => self::BOOTH,
                'waive' => [self::FEE],
                'amount' => '',
            ]))->payment(self::SLUG);
        }, '/zcov/rent');
        self::assertStringContainsString('Late fee waived.', $waived);

        $this->figuresLeft = 6;
        $this->fees = true;
        $this->drawer = true;
        $this->redirect(function (): void {
            $this->controller($this->request('POST', '/zcov/rent/payment', [], [
                'vendor_id' => self::VENDOR,
                'booth_id' => self::BOOTH,
                'amount' => '50.00',
                'tender_cash' => '50.00',
                'cash_tendered' => '50.00',
                'tender_card' => '0.00',
                'tender_checks' => '0.00',
            ]))->payment(self::SLUG);
        }, '/zcov/rent/receipt/');
    }

    public function testAWaiveCanMissStayOrClearTheBalance(): void
    {
        $missing = $this->capture(function (): void {
            $this->controller($this->request('POST', '/zcov/rent/waive', [], []))->waive(self::SLUG);
        });
        self::assertStringContainsString('Choose a late fee.', $missing);

        $this->figuresLeft = 2;
        $none = $this->capture(function (): void {
            $this->controller($this->request('POST', '/zcov/rent/waive', [], [
                'vendor_id' => self::VENDOR,
                'booth_id' => self::BOOTH,
                'journal_id' => self::FEE,
            ]))->waive(self::SLUG);
        });
        self::assertStringContainsString('Choose a late fee.', $none);

        $this->figuresLeft = 1;
        $this->fees = true;
        $cleared = $this->redirect(function (): void {
            $this->controller($this->request('POST', '/zcov/rent/waive', [], [
                'vendor_id' => self::VENDOR,
                'booth_id' => self::BOOTH,
                'all' => '1',
            ]))->waive(self::SLUG);
        }, '/zcov/rent');
        self::assertStringContainsString('Late fee waived.', $cleared);

        $this->figuresLeft = 4;
        $this->fees = true;
        $stays = $this->redirect(function (): void {
            $this->controller($this->request('POST', '/zcov/rent/waive', [], [
                'vendor_id' => self::VENDOR,
                'booth_id' => self::BOOTH,
                'journal_id' => self::FEE,
            ]))->waive(self::SLUG);
        }, '/zcov/rent/collect?vendor=' . rawurlencode(self::VENDOR));
        self::assertSame('Late fee waived.', $stays);
    }

    public function testARefusalStaysOnThePaymentOrWaivePage(): void
    {
        $this->figuresLeft = 0;
        $this->drawer = true;
        $paid = $this->capture(function (): void {
            $this->controller($this->request('POST', '/zcov/rent/payment', [], [
                'vendor_id' => self::VENDOR,
                'booth_id' => self::BOOTH,
                'amount' => '10.00',
            ]))->payment(self::SLUG);
        });
        self::assertStringContainsString('a rent receipt cannot exceed the open rent', $paid);

        $this->figuresLeft = 3;
        $this->fees = true;
        $this->refusePost = true;
        $waived = $this->capture(function (): void {
            $this->controller($this->request('POST', '/zcov/rent/waive', [], [
                'vendor_id' => self::VENDOR,
                'booth_id' => self::BOOTH,
                'all' => '1',
            ]))->waive(self::SLUG);
        });
        self::assertStringContainsString('That late fee is already waived.', $waived);
    }

    public function testAReceiptCanBeMissingOrPrinted(): void
    {
        $missing = $this->capture(function (): void {
            $this->controller($this->request('GET', '/zcov/rent/receipt/' . self::FEE, [], []))->slip(self::SLUG, self::FEE);
        });
        self::assertStringContainsString('that rent receipt is missing', $missing);

        $this->slip = true;
        $printed = $this->capture(function (): void {
            $this->controller($this->request('GET', '/zcov/rent/receipt/' . self::FEE, [], []))->slip(self::SLUG, self::FEE);
        });
        self::assertStringContainsString('Rent receipt', $printed);
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     */
    private function request(string $method, string $path, array $query, array $post): Request
    {
        $post['csrf'] = 'csrf-token';

        return new Request($method, $path, $query, $post, '', 'http://127.0.0.1', '203.0.113.10');
    }

    private function capture(callable $run): string
    {
        ob_start();
        try {
            $run();
        } finally {
            $html = ob_get_clean();
        }

        return is_string($html) ? $html : '';
    }

    private function redirect(callable $run, string $location): string
    {
        self::$location = '';
        ob_start();
        try {
            $run();
        } catch (ResponseSent) {
            ob_end_clean();
            self::assertStringContainsString($location, self::$location);
            $notice = $this->sessionBag['notice'] ?? '';

            return is_string($notice) ? $notice : '';
        }
        $html = ob_get_clean();
        $html = is_string($html) ? $html : '';
        $error = '';
        if (preg_match('/class="error">([^<]*)</', $html, $match) === 1) {
            $error = $match[1];
        }
        self::fail('the page did not redirect: ' . $error);
    }

    private function controller(Request $request): RentController
    {
        $app = $this->app($request);
        $statements = $this->statements();
        $mall = new MallSettings(
            $statements,
            new CompanyStore($statements, new DatabaseRole($app['pdo']), $app['pdo'], new CompanyLines()),
            new MallDay($statements),
        );
        $settings = new MallPayoutSettings($statements);
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('roleAccount')->willReturn(self::ACCOUNT);
        $ledger->method('post')->willReturnCallback(function (): string {
            if ($this->refusePost) {
                throw new DatabaseRefusal('That late fee is already waived.');
            }

            return self::FEE;
        });
        $ledger->method('newId')->willReturn(self::FEE);
        $ledger->method('reversed')->willReturn(false);
        $ledger->method('journal')->willReturn([
            'source_type' => 'late_fee',
            'source_reference' => self::BOOTH . ':2026-06',
            'is_reversal' => false,
            'posting_date' => '2026-06-01',
        ]);
        $ledger->method('lines')->willReturn([
            [
                'account_id' => self::ACCOUNT,
                'debit_minor' => 5000,
                'credit_minor' => 0,
                'subledger_type' => 'booth',
                'subledger_ref' => self::BOOTH,
            ],
            [
                'account_id' => self::ACCOUNT,
                'debit_minor' => 0,
                'credit_minor' => 5000,
                'subledger_type' => null,
                'subledger_ref' => null,
            ],
        ]);
        $lines = new RentLines();
        $clock = new MallClock($mall);
        $payouts = new PayoutStore($app['pdo'], $statements, new PayoutRunStore($statements, new OccupiedPack($statements)), new PayoutWindow());
        $fees = new LateFee($ledger, $statements);
        $receipts = new RentReceipt(
            $app['pdo'],
            $statements,
            $payouts,
            $settings,
            new RentPosting(
                $ledger,
                $payouts,
                new PayoutSettler(
                    $ledger,
                    $payouts,
                    new PayoutRunStore($statements, new OccupiedPack($statements)),
                    new PayoutRunLines($statements),
                    new JournalAudit(new Actor($app['session']), new AuditLog($statements)),
                    new VendorClawback($ledger, $statements),
                ),
                $settings,
                new \PerfectConsign\Feature\Rent\RentMoves($statements, $settings),
            ),
            new RentSlip($statements),
            new RentPayment(),
            $fees,
            new SaleCeiling($statements, new Actor($app['session']), new AuditLog($statements), $request),
        );

        return new RentController(
            $app['app'],
            new RentStore($statements, $mall, $lines, $ledger),
            new RentBook($lines),
            new RegisterStore($statements, $clock, new RegisterActivity($statements, $clock)),
            new ActiveRegister($app['pdo'], $app['session'], $mall, $clock),
            $settings,
            $receipts,
            new JournalCash($app['pdo']),
            new LateFeeDue($statements, $settings, $fees),
        );
    }

    /**
     * @return array{app: MallApp, pdo: PDO, session: Session}
     */
    private function app(Request $request): array
    {
        $this->sessionBag = [
            'csrf' => 'csrf-token',
            'owner_slug' => self::SLUG,
            'actor_role' => 'owner',
            'actor_account' => self::ACCOUNT,
            'credentials_version' => '1',
            'owner_name' => 'Ada',
            'signed_in_at' => (string) time(),
            'last_seen_at' => (string) time(),
        ];
        $bag = &$this->sessionBag;
        $session = $this->createStub(Session::class);
        $session->method('get')->willReturnCallback(static function (string $key) use (&$bag): mixed {
            return $bag[$key] ?? null;
        });
        $session->method('set')->willReturnCallback(static function (string $key, mixed $value) use (&$bag): void {
            $bag[$key] = is_scalar($value) ? (string) $value : '';
        });
        $session->method('has')->willReturnCallback(static function (string $key) use (&$bag): bool {
            return array_key_exists($key, $bag);
        });
        $session->method('delete')->willReturnCallback(static function (string $key) use (&$bag): void {
            unset($bag[$key]);
        });
        $open = false;
        $tenant = $this->createStub(PDOStatement::class);
        $tenant->method('execute')->willReturn(true);
        $tenant->method('fetch')->willReturn([
            'id' => '01900000-0000-7000-8000-0000000000bb',
            'slug' => self::SLUG,
            'schema_name' => 'tenant_' . str_repeat('ab', 16),
            'display_name' => 'Coverage',
        ]);
        $tenant->method('fetchColumn')->willReturn('1');
        $tenant->method('fetchAll')->willReturn([]);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willReturn(0);
        $pdo->method('prepare')->willReturn($tenant);
        $pdo->method('inTransaction')->willReturnCallback(static function () use (&$open): bool {
            return $open;
        });
        $pdo->method('beginTransaction')->willReturnCallback(static function () use (&$open): bool {
            $open = true;

            return true;
        });
        $pdo->method('commit')->willReturnCallback(static function () use (&$open): bool {
            $open = false;

            return true;
        });
        $pdo->method('rollBack')->willReturnCallback(static function () use (&$open): bool {
            $open = false;

            return true;
        });
        $logger = new NullLogger();
        $view = new View(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src');
        $tenants = new TenantLookup($pdo);
        $missing = new MissingPage($session, $logger, $view, $request, $tenants);
        $sessions = new LoginSession($session);
        $owner = new OwnerGate(
            $pdo,
            $session,
            $logger,
            $request,
            $tenants,
            $missing,
            new StaffSignIn($pdo, $logger, $sessions, new SignInFailureRetention($pdo)),
            $sessions,
        );
        $door = new FrontDoor(
            $missing,
            new PriorClose($session, $request),
            $owner,
            new PostedRefusal($logger),
            new Actor($session),
            new CredentialStamp($pdo, $session, new DatabaseRole($pdo)),
        );
        $rows = $this->statements();
        $mall = new MallSettings(
            $rows,
            new CompanyStore($rows, new DatabaseRole($pdo), $pdo, new CompanyLines()),
            new MallDay($rows),
        );

        return [
            'app' => new MallApp(
                $pdo,
                $session,
                $view,
                $request,
                new OwnerAccess(),
                $mall,
                new ActiveRegister($pdo, $session, $mall, new MallClock($mall)),
                $tenants,
                $door,
            ),
            'pdo' => $pdo,
            'session' => $session,
        ];
    }

    private function statements(): Statements
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('rows')->willReturnCallback(function (string $sql): array {
            if (str_contains($sql, 'SELECT time_zone FROM mall')) {
                return [['time_zone' => 'America/Los_Angeles']];
            }
            if (str_contains($sql, 'SELECT rent_cycle_day FROM mall')) {
                return [['rent_cycle_day' => 1]];
            }
            if (str_contains($sql, 'street, street2')) {
                return [[
                    'street' => '',
                    'street2' => '',
                    'city' => '',
                    'state' => '',
                    'postal' => '',
                    'phone' => '',
                    'email' => '',
                ]];
            }
            if (str_contains($sql, 'require_second_signature FROM mall')) {
                return [['require_second_signature' => false]];
            }
            if (str_contains($sql, 'booth_width_ft')) {
                return [[
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
                    'booth_rentals_enabled' => true,
                    'rent_cycle_day' => 1,
                    'consignment_enabled' => true,
                    'store_stock_enabled' => false,
                    'receipt_footer' => '',
                    'operating_mode' => 'both',
                ]];
            }
            if (str_contains($sql, 'late_fee_first_minor')) {
                return [[
                    'operating_mode' => 'both',
                    'payout_cadence' => 'monthly',
                    'rent_prepaid_from_prior_sales' => $this->prepaid,
                    'booth_rentals_enabled' => true,
                    'rent_cycle_day' => 1,
                    'consignment_enabled' => true,
                    'store_stock_enabled' => false,
                    'late_fees_enabled' => false,
                    'late_fee_grace_days' => 0,
                    'late_fee_first_minor' => 0,
                    'late_fee_next_minor' => 0,
                    'late_fee_effective_on' => null,
                    'require_second_signature' => false,
                ]];
            }
            if (str_contains($sql, 'closed_at IS NULL')) {
                return $this->drawer ? [['id' => self::DRAWER, 'name' => 'Front']] : [];
            }

            return [];
        });
        $statements->method('bound')->willReturnCallback(function (string $sql): array {
            if (str_contains($sql, 'AS open_minor') && str_contains($sql, 'booth_rent_receivable')) {
                if ($this->figuresLeft < 1) {
                    return [];
                }
                $this->figuresLeft--;

                return [['open_minor' => 5000]];
            }
            if (str_contains($sql, 'vendor_name FROM vendors')) {
                return [['vendor_name' => 'Ada']];
            }
            if (str_contains($sql, 'AS open')) {
                return [['id' => self::BOOTH, 'code' => 'A1']];
            }
            if (str_contains($sql, 'AS late_minor')) {
                return [['late_minor' => 0]];
            }
            if (str_contains($sql, 'journal.source_type AS source') && $this->fees) {
                return [[
                    'journal_id' => self::FEE,
                    'source' => 'late_fee',
                    'posted_on' => '2026-06-01',
                    'starts_on' => '2026-01-01',
                    'booth' => 'A1',
                    'booth_id' => self::BOOTH,
                    'debit_minor' => 5000,
                    'credit_minor' => 0,
                ]];
            }
            if (str_contains($sql, 'amount_minor') && str_contains($sql, 'LIMIT 1')) {
                return $this->cover ? [['amount_minor' => 10000]] : [];
            }
            if (str_contains($sql, 'starts_on') && str_contains($sql, 'LIMIT 1')) {
                return $this->cover ? [['starts_on' => '2026-01-01']] : [];
            }
            if (str_contains($sql, "source_type = 'rent_payment'") && $this->slip) {
                return [[
                    'journal_no' => 4,
                    'posted_on' => '2026-06-15',
                    'debit_total_minor' => 5000,
                ]];
            }

            return [];
        });
        $statements->method('change');
        $statements->method('affected')->willReturn(1);

        return $statements;
    }
}

namespace PerfectConsign\Bootstrap;

if (!function_exists(__NAMESPACE__ . '\\header')) {
    function header(string $header, bool $replace = true, int $response_code = 0): void
    {
        \PerfectConsign\Tests\Feature\Rent\RentControllerCoverageTest::$location = $header;
        \header($header, $replace, $response_code);
    }
}
