<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Mall;

use PerfectConsign\Bootstrap\AuditLog;
use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Feature\Backup\BackupStore;
use PerfectConsign\Feature\Backup\NightlyCopy;
use PerfectConsign\Feature\Company\CompanyLines;
use PerfectConsign\Feature\Company\CompanyStore;
use PerfectConsign\Feature\Inventory\LabelCatalog;
use PerfectConsign\Feature\Mall\MallController;
use PerfectConsign\Feature\Mall\MallDay;
use PerfectConsign\Feature\Mall\MallMoneyForm;
use PerfectConsign\Feature\Mall\MallPayoutSettings;
use PerfectConsign\Feature\Mall\MallRentDraft;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Feature\Mall\ReceiptFooter;
use PerfectConsign\Feature\Mall\ReceiptMailDraft;
use PerfectConsign\Feature\Mall\ReceiptMailSettings;
use PerfectConsign\Feature\MallSale\MallSaleController;
use PerfectConsign\Feature\Offer\OfferController;
use PerfectConsign\Feature\Offer\OfferStore;
use PerfectConsign\Feature\Offer\VendorOfferController;
use PerfectConsign\Feature\Opening\OpeningController;
use PerfectConsign\Feature\Opening\OpeningStore;
use PerfectConsign\Feature\Party\PartyStore;
use PerfectConsign\Feature\Vendor\VendorPreview;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalDraft;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Mail\SealedSecret;
use PerfectConsign\Tests\Feature\Coverage\CapturesPage;
use PerfectConsign\Tests\Feature\Coverage\MallDoor;
use PerfectConsign\Tests\Feature\Coverage\RoutedStatements;
use PHPUnit\Framework\TestCase;
use PDO;
use PDOStatement;

final class MallMissCoverageTest extends TestCase
{
    use CapturesPage;

    private const OFFER = '01900000-0000-7000-8000-0000000000f1';

    public static function journal(): string
    {
        return self::OFFER;
    }

    private const VENDOR = '01900000-0000-7000-8000-0000000000c1';

    public function testAMallSaleWithoutASignerIsRefused(): void
    {
        $door = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/mall-sale', [], [
            'csrf' => 'csrf-token',
            'absorbed_by' => 'mall',
            'percent' => '10',
            'name' => 'Spring',
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-01-02',
        ]));
        $door->session->set('owner_name', '');
        $controller = new MallSaleController($door->app, $this->routed(), $door->actor, new AuditLog($this->routed()));
        $this->capture(function () use ($controller): void {
            $controller->save(MallDoor::SLUG);
        });
        self::assertSame('The signed-in person is missing.', $door->bag['notice'] ?? null);
    }

    public function testReceiptAndBuildingRefusalsStayOnSettings(): void
    {
        $receipt = $this->settings('receipt', [
            'csrf' => 'csrf-token',
            'receipt_footer' => 'Thanks',
            'receipt_mail_via' => '',
        ]);
        self::assertStringContainsString('the books refused', $receipt);

        $building = $this->settings('building', [
            'csrf' => 'csrf-token',
            'booth_width_ft' => '10',
            'booth_depth_ft' => '10',
            'floor_width_ft' => '40',
            'floor_depth_ft' => '40',
            'floor_days' => '30',
            'label_stock' => 'avery_8160',
            'time_zone' => 'America/Los_Angeles',
        ]);
        self::assertStringContainsString('the books refused', $building);
    }

    public function testAMissingClockAndABlankMailRowAreRefused(): void
    {
        $clock = new MallDay($this->routed());
        try {
            $clock->today();
            self::fail('a missing clock answered');
        } catch (\RuntimeException $error) {
            self::assertSame('the mall has no clock', $error->getMessage());
        }
        $mall = $this->mall($this->routed());
        try {
            $mall->getGmailAddress();
            self::fail('a blank mail row answered');
        } catch (\RuntimeException $error) {
            self::assertSame('the mall has no clock', $error->getMessage());
        }
        try {
            $mall->boothSize();
            self::fail('a blank mall row answered');
        } catch (\RuntimeException $error) {
            self::assertSame('the mall has no clock', $error->getMessage());
        }
    }

    public function testReceiptMailAndFooterRefusals(): void
    {
        ReceiptFooter::refuseMeasuredHeads(true);
        try {
            ReceiptFooter::text('Thanks');
            self::fail('an unmeasured footer was saved');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('receipt footer is 400 characters or less', $error->getMessage());
        }
        try {
            ReceiptMailDraft::password('not a password');
            self::fail('a short app password was kept');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('a Gmail app password is 16 letters or digits', $error->getMessage());
        }

        $empty = new ReceiptMailSettings($this->mailStatements(false));
        $empty->save('server', '', '');
        $sealed = SealedSecret::seal('abcdefghijklmnop');
        $kept = new ReceiptMailSettings($this->mailStatements(true, $sealed));
        $kept->save('gmail', 'owner@gmail.com', '');
        $fresh = new ReceiptMailSettings($this->mailStatements(false));
        $fresh->save('gmail', 'owner@gmail.com', 'abcdefghijklmnop');
        try {
            (new ReceiptMailSettings($this->mailStatements(false)))->save('gmail', '', '');
            self::fail('gmail without a password was saved');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Gmail needs an address and an app password', $error->getMessage());
        }

        $statements = $this->routed();
        $this->routeMall($statements);
        $this->mall($statements)->setReceiptMail('server', '', '');
    }

    public function testMoneyWritesAndRentDraftBranches(): void
    {
        $refused = $this->money('America/Los_Angeles');
        $refused['statements']->changeError = new DatabaseRefusal('the books refused');
        $outcome = $refused['form']->apply(MallDoor::SLUG);
        self::assertSame('the books refused', $outcome->error);

        $invalid = $this->money('Nope');
        $bad = $invalid['form']->apply(MallDoor::SLUG);
        self::assertNotSame('', $bad->error);

        $blank = $this->rent([
            'operating_mode' => '',
            'booth_rentals_enabled' => '1',
            'rent_cycle_day' => '',
        ]);
        self::assertNull($blank->read());
        self::assertTrue($blank->rentals);

        $wide = $this->rent([
            'operating_mode' => '',
            'booth_rentals_enabled' => '1',
            'rent_cycle_day' => '99',
        ]);
        self::assertSame('the rent cycle day is a whole number from 1 to 28', $wide->read());

        $off = $this->rent(['operating_mode' => 'consignor']);
        self::assertNull($off->read());
        self::assertFalse($off->rentals);

        $late = $this->rent([
            'operating_mode' => '',
            'booth_rentals_enabled' => '1',
            'rent_cycle_day' => '15',
            'late_fees_enabled' => '1',
            'late_fee_grace_days' => '0',
            'late_fee_first' => 'abc',
            'late_fee_next' => '1.00',
        ]);
        self::assertSame('enter the late fee in dollars and cents', $late->read());
    }

    public function testAnEndedDealAndAMissedUpdateStayOnTheList(): void
    {
        $statements = $this->routed();
        $this->routeMall($statements);
        $statements->boundRoutes[] = ['match' => 'FROM offers', 'rows' => [$this->offerRow('store', '')]];
        $statements->changeError = new DatabaseRefusal('the books refused');
        $door = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/offers/' . self::OFFER . '/end', [], [
            'csrf' => 'csrf-token',
        ]));
        $mall = $this->mall($statements);
        $store = new OfferStore($statements);
        $controller = new OfferController($door->app, $store, $mall, new VendorPreview($door->app, $door->actor, $this->createStub(PartyStore::class)));
        $html = $this->capture(function () use ($controller): void {
            $controller->end(MallDoor::SLUG, self::OFFER);
        });
        self::assertStringContainsString('the books refused', $html);

        $update = $this->routed();
        $update->boundRoutes[] = ['match' => 'FROM offers', 'rows' => [$this->offerRow('store', '')]];
        $update->affectedCount = 0;
        $saving = new OfferStore($update);
        try {
            $saving->save(self::OFFER, $this->deal(), null);
            self::fail('a missed update was saved');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('That deal is not yours.', $error->getMessage());
        }

        $vendorStatements = $this->routed();
        $this->routeMall($vendorStatements);
        $vendorStatements->boundRoutes[] = ['match' => 'FROM offers', 'rows' => [$this->offerRow('vendor', self::VENDOR)]];
        $vendorStatements->changeError = new DatabaseRefusal('the books refused');
        $vendorDoor = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/vendor/offers/' . self::OFFER . '/end', [], [
            'csrf' => 'csrf-token',
        ]));
        $vendorDoor->session->set('actor_vendor', self::VENDOR);
        $vendor = new VendorOfferController(
            $vendorDoor->app,
            new OfferStore($vendorStatements),
            $this->mall($vendorStatements),
            new VendorPreview($vendorDoor->app, $vendorDoor->actor, $this->createStub(PartyStore::class)),
        );
        $page = $this->capture(function () use ($vendor): void {
            $vendor->vendorEnd(MallDoor::SLUG, self::OFFER);
        });
        self::assertStringContainsString('the books refused', $page);
    }

    public function testOpeningRefusalsABadAmountAndAPostedOpening(): void
    {
        $closed = $this->opening(false);
        $html = $this->capture(function () use ($closed): void {
            $closed['controller']->submit(MallDoor::SLUG);
        });
        self::assertStringContainsString('opening is available only', $html);

        $amount = $this->opening(true, [
            'line_account_1' => self::OFFER,
            'line_amount_1' => 'nope',
            'line_side_1' => 'debit',
        ]);
        $bad = $this->capture(function () use ($amount): void {
            $amount['controller']->submit(MallDoor::SLUG);
        });
        self::assertStringContainsString('dollars and cents', $bad);

        $posted = $this->opening(true, [
            'line_account_1' => self::OFFER,
            'line_amount_1' => '10.00',
            'line_side_1' => 'debit',
            'line_account_2' => self::VENDOR,
            'line_amount_2' => '10.00',
            'line_side_2' => 'credit',
        ]);
        $this->capture(function () use ($posted): void {
            $posted['controller']->submit(MallDoor::SLUG);
        });
        self::assertSame('Opening posted.', $posted['door']->bag['notice'] ?? null);

        $form = $this->opening(true);
        $page = $this->capture(function () use ($form): void {
            $form['controller']->form(MallDoor::SLUG);
        });
        self::assertStringContainsString('Posting date', $page);

        $pdo = $this->createStub(PDO::class);
        $first = $this->createStub(PDOStatement::class);
        $first->method('fetchColumn')->willReturn(0);
        $pdo->method('query')->willReturnOnConsecutiveCalls($first, false);
        try {
            (new OpeningStore($pdo))->openingAllowed();
            self::fail('a failed live count was allowed');
        } catch (\RuntimeException $error) {
            self::assertSame('opening live count query failed', $error->getMessage());
        }

        $view = new View(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src');
        ob_start();
        $view->render('Feature/Opening/opening', [
            'slug' => MallDoor::SLUG,
            'csrf' => 'csrf-token',
            'allowed' => true,
            'accounts' => [['id' => self::OFFER, 'code' => '1000', 'name' => 'Cash']],
            'today' => '2026-01-01',
        ]);
        $rendered = ob_get_clean();
        self::assertIsString($rendered);
        self::assertStringContainsString('name="posting_date"', $rendered);
    }

    /**
     * @param array<string, string> $post
     */
    private function settings(string $part, array $post): string
    {
        $statements = $this->routed();
        $this->routeMall($statements);
        $statements->changeError = new DatabaseRefusal('the books refused');
        $door = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/settings/' . $part, [], $post));
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchColumn')->willReturn(false);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $controller = new MallController(
            $door->app,
            $this->mall($statements),
            new MallPayoutSettings($statements),
            new BackupStore($pdo),
            new NightlyCopy(),
            new LabelCatalog(),
        );

        return $this->capture(function () use ($controller, $part): void {
            $controller->savePart(MallDoor::SLUG, $part);
        });
    }

    /**
     * @return array{form: MallMoneyForm, statements: RoutedStatements}
     */
    private function money(string $zone): array
    {
        $statements = $this->routed();
        $this->routeMall($statements, $zone);
        $door = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/settings/money', [], [
            'csrf' => 'csrf-token',
            'default_store_take' => '10',
            'tax_rate' => '0',
            'operating_mode' => 'consignor',
        ]));
        $payouts = new MallPayoutSettings($statements);
        $draft = new MallRentDraft($door->app, $payouts);

        return ['form' => new MallMoneyForm($door->app, $this->mall($statements), $payouts, $draft), 'statements' => $statements];
    }

    /**
     * @param array<string, string> $post
     */
    private function rent(array $post): MallRentDraft
    {
        $statements = $this->routed();
        $this->routeMall($statements);
        $door = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/settings/money', [], ['csrf' => 'csrf-token'] + $post));

        return new MallRentDraft($door->app, new MallPayoutSettings($statements));
    }

    /**
     * @param array<string, string> $lines
     * @return array{controller: OpeningController, ledger: LedgerStore, door: MallDoor}
     */
    private function opening(bool $allowed, array $lines = []): array
    {
        $count = $this->createStub(PDOStatement::class);
        $count->method('fetchColumn')->willReturn($allowed ? 0 : 1);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('query')->willReturn($count);
        $door = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/opening', [], ['csrf' => 'csrf-token', 'posting_date' => '2026-01-01'] + $lines));
        $ledger = new class extends LedgerStore {
            public function __construct()
            {
            }

            public function post(JournalDraft $draft): string
            {
                return MallMissCoverageTest::journal();
            }

            public function postableAccounts(bool $nonControlOnly): array
            {
                return [['id' => MallMissCoverageTest::journal(), 'code' => '1000', 'name' => 'Cash']];
            }
        };
        $parties = $this->createStub(PartyStore::class);
        $parties->method('vendors')->willReturn([]);
        $parties->method('booths')->willReturn([]);

        return [
            'controller' => new OpeningController($door->app, $ledger, new OpeningStore($pdo), $parties),
            'ledger' => $ledger,
            'door' => $door,
        ];
    }

    private function mailStatements(bool $password, string $sealed = ''): RoutedStatements
    {
        $statements = $this->routed();
        $statements->rowRoutes[] = ['match' => 'receipt_mail_via', 'rows' => [[
            'receipt_mail_via' => 'server',
            'gmail_address' => 'owner@gmail.com',
            'gmail_app_password' => $password ? $sealed : '',
        ]]];

        return $statements;
    }

    /**
     * @return array<string, mixed>
     */
    private function offerRow(string $scope, string $scopeId): array
    {
        return [
            'id' => self::OFFER,
            'name' => 'Deal',
            'scope' => $scope,
            'kind' => 'bundle',
            'threshold_qty' => 2,
            'deal_minor' => 1000,
            'funded_by' => 'store',
            'starts_on' => '2026-01-01',
            'ends_on' => null,
            'scope_id' => $scopeId,
            'item_code' => null,
            'item_vendor' => null,
            'scope_label' => 'Store',
        ];
    }

    /**
     * @return array{name: string, scope: string, kind: string, threshold: string, price: string, funded_by: string, starts_on: string, ends_on: string, item_code: string, vendor_id: string}
     */
    private function deal(): array
    {
        return [
            'name' => 'Deal',
            'scope' => 'store',
            'kind' => 'bundle',
            'threshold' => '2',
            'price' => '10.00',
            'funded_by' => 'store',
            'starts_on' => '2026-01-01',
            'ends_on' => '',
            'item_code' => '',
            'vendor_id' => '',
        ];
    }

    private function routeMall(RoutedStatements $statements, string $zone = 'America/Los_Angeles'): void
    {
        $statements->rowRoutes[] = ['match' => 'receipt_mail_via', 'rows' => [[
            'receipt_mail_via' => 'server',
            'gmail_address' => '',
            'gmail_app_password' => '',
        ]]];
        $statements->rowRoutes[] = ['match' => 'payout_cadence', 'rows' => [[
            'operating_mode' => 'both',
            'payout_cadence' => 'monthly',
            'rent_prepaid_from_prior_sales' => false,
            'booth_rentals_enabled' => false,
            'rent_cycle_day' => 1,
            'consignment_enabled' => false,
            'store_stock_enabled' => false,
            'late_fees_enabled' => false,
            'late_fee_grace_days' => 0,
            'late_fee_first_minor' => 0,
            'late_fee_next_minor' => 0,
            'late_fee_effective_on' => null,
            'require_second_signature' => false,
        ]]];
        $statements->rowRoutes[] = ['match' => 'SELECT time_zone FROM mall', 'rows' => [['time_zone' => $zone]]];
        $statements->rowRoutes[] = ['match' => 'booth_width_ft', 'rows' => [[
            'time_zone' => $zone,
            'booth_width_ft' => 10,
            'booth_depth_ft' => 10,
            'floor_width_ft' => 40,
            'floor_depth_ft' => 40,
            'commission_bps' => 1000,
            'floor_days' => null,
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
            'consignment_enabled' => false,
            'store_stock_enabled' => false,
            'receipt_footer' => '',
            'operating_mode' => 'both',
        ]]];
    }

    private function mall(RoutedStatements $statements): MallSettings
    {
        $pdo = $this->createStub(PDO::class);

        return new MallSettings(
            $statements,
            new CompanyStore($statements, new DatabaseRole($pdo), $pdo, new CompanyLines()),
            new MallDay($statements),
        );
    }

    private function routed(): RoutedStatements
    {
        return new RoutedStatements($this->createStub(PDO::class));
    }
}
