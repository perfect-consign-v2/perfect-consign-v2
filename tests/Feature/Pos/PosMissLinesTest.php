<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Pos;

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
use PerfectConsign\Feature\Catalog\NameListStore;
use PerfectConsign\Feature\Company\CompanyLines;
use PerfectConsign\Feature\Company\CompanyStore;
use PerfectConsign\Feature\Inventory\IntakeCatalog;
use PerfectConsign\Feature\Inventory\InventoryStore;
use PerfectConsign\Feature\Inventory\ItemWriter;
use PerfectConsign\Feature\Inventory\StoreTake;
use PerfectConsign\Feature\Mall\MallClock;
use PerfectConsign\Feature\Mall\MallDay;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Feature\Mall\ReceiptMailSettings;
use PerfectConsign\Feature\Party\AssignmentBook;
use PerfectConsign\Feature\Party\PartyIdentity;
use PerfectConsign\Feature\Party\PartyStore;
use PerfectConsign\Feature\Party\SellerPaths;
use PerfectConsign\Feature\Payout\OccupiedPack;
use PerfectConsign\Feature\Payout\PayoutRunStore;
use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Feature\Payout\PayoutWindow;
use PerfectConsign\Feature\Payout\VendorClawback;
use PerfectConsign\Feature\Pos\BoothCorrection;
use PerfectConsign\Feature\Pos\CheckoutBasket;
use PerfectConsign\Feature\Pos\CustomerController;
use PerfectConsign\Feature\Pos\CustomerDirectory;
use PerfectConsign\Feature\Pos\CustomerStore;
use PerfectConsign\Feature\Pos\DraftAwareDiscard;
use PerfectConsign\Feature\Pos\GiftIssue;
use PerfectConsign\Feature\Pos\GiftIssuePosting;
use PerfectConsign\Feature\Pos\InstantItem;
use PerfectConsign\Feature\Pos\LayawayBook;
use PerfectConsign\Feature\Pos\LayawaySlip;
use PerfectConsign\Feature\Pos\PosAddController;
use PerfectConsign\Feature\Pos\PosBuyerController;
use PerfectConsign\Feature\Pos\PosCartView;
use PerfectConsign\Feature\Pos\PosController;
use PerfectConsign\Feature\Pos\PosLayawayController;
use PerfectConsign\Feature\Pos\PosLineController;
use PerfectConsign\Feature\Pos\PosLookupController;
use PerfectConsign\Feature\Pos\PosPage;
use PerfectConsign\Feature\Pos\PosPayController;
use PerfectConsign\Feature\Pos\PosRegister;
use PerfectConsign\Feature\Pos\PosTenderController;
use PerfectConsign\Feature\Pos\ReceiptChange;
use PerfectConsign\Feature\Pos\ReceiptEmailController;
use PerfectConsign\Feature\Pos\ReceiptLetter;
use PerfectConsign\Feature\Pos\ReceiptMailer;
use PerfectConsign\Feature\Pos\ReceiptView;
use PerfectConsign\Feature\Pos\RegisterGate;
use PerfectConsign\Feature\Pos\SaleCheckout;
use PerfectConsign\Feature\Pos\SaleHold;
use PerfectConsign\Feature\Pos\SaleJournal;
use PerfectConsign\Feature\Pos\SalePoster;
use PerfectConsign\Feature\Pos\SaleReceipt;
use PerfectConsign\Feature\Pos\SaleReversal;
use PerfectConsign\Feature\Pos\SaleTicketAttach;
use PerfectConsign\Feature\Pos\ScanSearch;
use PerfectConsign\Feature\Pos\SnapController;
use PerfectConsign\Feature\Pos\SnapSync;
use PerfectConsign\Feature\Pos\TenderSplit;
use PerfectConsign\Feature\Pos\TicketBuyer;
use PerfectConsign\Feature\Pos\TicketCustomer;
use PerfectConsign\Feature\Pos\TicketDrafts;
use PerfectConsign\Feature\Pos\TicketLines;
use PerfectConsign\Feature\Pos\TicketPriceChange;
use PerfectConsign\Feature\Pos\TicketPricing;
use PerfectConsign\Feature\Pos\TicketQueue;
use PerfectConsign\Feature\Pos\TicketStore;
use PerfectConsign\Feature\Pos\TicketTenders;
use PerfectConsign\Feature\Register\RegisterActivity;
use PerfectConsign\Feature\Register\RegisterStore;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalCash;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Ledger\PostedLines;
use PerfectConsign\Shared\Auth\OwnerAccess;
use PerfectConsign\Shared\Database\CatalogLinks;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Mail\GmailSender;
use PerfectConsign\Shared\Mail\OutboundMail;
use PerfectConsign\Shared\Mail\PlatformOperatorNotice;
use PerfectConsign\Tests\Feature\Coverage\CapturesPage;
use PerfectConsign\Tests\Feature\Coverage\MallDoor;
use PerfectConsign\Tests\Feature\Coverage\RoutedStatements;
use PerfectConsign\Tests\Feature\Password\ReadyMailbox;
use PDO;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class PosMissLinesTest extends TestCase
{
    use CapturesPage;

    private const TICKET = '01900000-0000-7000-8000-0000000000c4';

    private const ITEM = '01900000-0000-7000-8000-0000000000c3';

    public function testAnOpenTicketShowsABlankLineAndAProbeDraft(): void
    {
        $door = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/pos', ['instant' => '1'], [
            'discount_kind' => 'nope',
            'description' => 'Hat',
            'vendor_id' => MallDoor::VENDOR,
            'vendor_label' => 'Ada',
            'price' => '4.00',
        ]));
        $statements = new CartStatements($this->createStub(PDO::class));
        $drafts = new TicketDrafts($statements);
        $drafts->setProbeMissing(['barcode']);
        $page = $this->page($door, $statements, $drafts);
        $html = $this->capture(function () use ($page): void {
            $page->instant(MallDoor::SLUG, 'Enter a description.');
        });
        self::assertStringContainsString('Enter a description.', $html);
        self::assertStringContainsString('barcode', $html);
        self::assertStringContainsString(str_repeat('A', 41), $html);
    }

    public function testParkingAndBadPricesRenderTheRegister(): void
    {
        $drafts = new TicketDrafts($this->routed([['match' => 'ticket_draft_lines', 'rows' => [['present' => 1]]]]));
        $door = $this->door('POST', '/' . MallDoor::SLUG . '/pos/park', [], ['csrf' => 'csrf-token', 'park_label' => 'Hat']);
        $this->capture(function () use ($door, $drafts): void {
            $this->pos($door, $drafts)->park(MallDoor::SLUG);
        });

        $json = $this->door('POST', '/' . MallDoor::SLUG . '/pos/park', [], ['csrf' => 'csrf-token'], 'application/json');
        $this->capture(function () use ($json, $drafts): void {
            $this->pos($json, $drafts)->park(MallDoor::SLUG);
        });

        $bad = $this->door('POST', '/' . MallDoor::SLUG . '/pos/price', [], [
            'csrf' => 'csrf-token',
            'line_no' => '1',
            'price' => 'abc',
        ], 'application/json');
        $this->capture(function () use ($bad): void {
            $this->pos($bad, new TicketDrafts($bad->statements))->price(MallDoor::SLUG);
        });

        $plain = $this->door('POST', '/' . MallDoor::SLUG . '/pos/price', [], [
            'csrf' => 'csrf-token',
            'line_no' => '1',
            'price' => 'abc',
        ]);
        $html = $this->capture(function () use ($plain): void {
            $this->pos($plain, new TicketDrafts($plain->statements))->price(MallDoor::SLUG);
        });
        self::assertNotSame('', $html);

        $line = $this->door('POST', '/' . MallDoor::SLUG . '/pos/remove', [], ['csrf' => 'csrf-token', 'line_no' => '0']);
        $this->capture(function () use ($line): void {
            $this->pos($line, new TicketDrafts($line->statements))->remove(MallDoor::SLUG);
        });

        $recall = $this->door('POST', '/' . MallDoor::SLUG . '/pos/recall', [], ['csrf' => 'csrf-token', 'ticket_id' => 'nope']);
        $this->capture(function () use ($recall): void {
            $this->pos($recall, new TicketDrafts($recall->statements))->recall(MallDoor::SLUG);
        });
    }

    public function testInstantAddRefusesAHeldSaleABlankVendorAndRemovesADraft(): void
    {
        $door = $this->door('POST', '/' . MallDoor::SLUG . '/pos/instant', [], [
            'csrf' => 'csrf-token',
            'vendor_id' => '',
            'description' => '',
            'price' => '',
            'discount_kind' => 'nope',
        ]);
        $ledger = $this->saleLedger();
        $door->session->set('sale:' . MallDoor::SLUG, MallDoor::JOURNAL);
        $html = $this->capture(function () use ($door, $ledger): void {
            $this->adds($door, $ledger)->instant(MallDoor::SLUG);
        });
        self::assertStringContainsString('start a new sale', $html);

        $door = $this->door('POST', '/' . MallDoor::SLUG . '/pos/instant', [], [
            'csrf' => 'csrf-token',
            'draft_no' => '2',
            'vendor_id' => 'nope',
            'description' => 'Hat',
            'price' => '2.00',
        ]);
        $html = $this->capture(function () use ($door): void {
            $this->adds($door)->instant(MallDoor::SLUG);
        });
        self::assertStringContainsString('vendor', strtolower($html));

        $door = $this->door('POST', '/' . MallDoor::SLUG . '/pos/draft/2/remove', [], ['csrf' => 'csrf-token']);
        $this->capture(function () use ($door): void {
            $this->adds($door)->removeDraft(MallDoor::SLUG, '2');
        });
    }

    public function testBuyerLineLayawayAndTenderRefusalsRepaint(): void
    {
        $door = $this->door('POST', '/' . MallDoor::SLUG . '/pos/buyer', [], ['csrf' => 'csrf-token', 'vendor_id' => MallDoor::VENDOR]);
        $bad = new CartStatements($this->createStub(PDO::class));
        $bad->badPrice = true;
        $this->capture(function () use ($door, $bad): void {
            $this->buyer($door, $bad)->save(MallDoor::SLUG);
        });

        $door = $this->door('POST', '/' . MallDoor::SLUG . '/pos/line-discount', [], [
            'csrf' => 'csrf-token',
            'discount_action' => 'set',
            'discount_kind' => 'flat',
            'discount_value' => 'abc',
        ]);
        $this->capture(function () use ($door): void {
            $this->lines($door)->discount(MallDoor::SLUG);
        });

        $door = $this->door('POST', '/' . MallDoor::SLUG . '/pos/quantity', [], ['csrf' => 'csrf-token', 'line_no' => 'x', 'quantity' => '1']);
        $this->capture(function () use ($door): void {
            $this->lines($door)->quantity(MallDoor::SLUG);
        });

        $door = $this->door('POST', '/' . MallDoor::SLUG . '/pos/quantity', [], ['csrf' => 'csrf-token', 'line_no' => '1', 'quantity' => '0']);
        $this->capture(function () use ($door): void {
            $this->lines($door)->quantity(MallDoor::SLUG);
        });

        $door = $this->door('POST', '/' . MallDoor::SLUG . '/pos/layaway/cancel', [], [
            'csrf' => 'csrf-token',
            'ticket_id' => self::TICKET,
        ]);
        $slipStatements = new CartStatements($this->createStub(PDO::class));
        $slipStatements->badPrice = true;
        $slips = new LayawaySlip($slipStatements, new TicketPricing($slipStatements), new LayawayBook($slipStatements, $door->ledger));
        $this->capture(function () use ($door, $slips): void {
            $this->layaway($door, $slips)->cancel(MallDoor::SLUG);
        });

        $door = $this->door('POST', '/' . MallDoor::SLUG . '/pos/gift', [], [
            'csrf' => 'csrf-token',
            'gift_amount' => 'abc',
            'gift_tender' => 'cash',
            'form_token' => str_repeat('a', 32),
        ]);
        $this->capture(function () use ($door): void {
            $this->tenders($door)->gift(MallDoor::SLUG);
        });

        $door = $this->door('POST', '/' . MallDoor::SLUG . '/pos/tender', [], [
            'csrf' => 'csrf-token',
            'tender_role' => 'cash',
            'tender_amount' => 'abc',
        ]);
        $this->capture(function () use ($door): void {
            $this->tenders($door)->apply(MallDoor::SLUG);
        });
    }

    public function testPayVoidReturnAndAMissingGiftNumber(): void
    {
        $door = $this->door('POST', '/' . MallDoor::SLUG . '/pos/pay', [], ['csrf' => 'csrf-token', 'tender_cash' => 'abc']);
        $this->capture(function () use ($door): void {
            $this->pay($door)->pay(MallDoor::SLUG);
        });

        $door = $this->door('POST', '/' . MallDoor::SLUG . '/pos/receipt/' . MallDoor::JOURNAL . '/void', [], ['csrf' => 'csrf-token'], 'application/json');
        $this->capture(function () use ($door): void {
            $this->pay($door)->voidSale(MallDoor::SLUG, MallDoor::JOURNAL);
        });

        $sale = $this->saleLedger();
        $door = $this->door('POST', '/' . MallDoor::SLUG . '/pos/receipt/' . MallDoor::JOURNAL . '/void', [], ['csrf' => 'csrf-token'], 'application/json');
        $this->capture(function () use ($door, $sale): void {
            $this->pay($door, $sale)->voidSale(MallDoor::SLUG, MallDoor::JOURNAL);
        });

        $door = $this->door('POST', '/' . MallDoor::SLUG . '/pos/receipt/' . MallDoor::JOURNAL . '/return', [], [
            'csrf' => 'csrf-token',
            'item_id' => self::ITEM,
            'return_tender' => 'cash',
        ], 'application/json');
        $this->capture(function () use ($door): void {
            $this->pay($door)->returnItem(MallDoor::SLUG, MallDoor::JOURNAL);
        });

        $door = $this->door('POST', '/' . MallDoor::SLUG . '/pos/receipt/' . MallDoor::JOURNAL . '/return', [], [
            'csrf' => 'csrf-token',
            'item_id' => self::ITEM,
            'return_tender' => 'cash',
        ]);
        $receipts = new RoutedStatements($this->createStub(PDO::class));
        $receipts->boundRoutes[] = ['match' => 'tax_waiver_reason', 'rows' => [['discount_reason' => '', 'tax_waiver_reason' => 'resale']]];
        $receipts->boundRoutes[] = ['match' => 'cash_rounding', 'rows' => []];
        $receipts->boundRoutes[] = ['match' => 'sale.quantity', 'rows' => [[
            'item_id' => self::ITEM,
            'price_minor' => 100,
            'quantity' => 'nope',
            'commission_minor' => 0,
            'tax_minor' => 0,
            'discount_minor' => 0,
            'store_discount_minor' => 0,
            'offer_name' => '',
            'code' => 'HAT',
            'description' => 'Hat',
            'vendor_name' => 'Ada',
            'tax_exempt' => false,
            'returned' => false,
        ]]];
        $poster = new SalePoster($sale, new SaleReceipt($sale, $receipts), new ReceiptChange(), new SaleTicketAttach($receipts), $this->reversal($sale, $receipts));
        $view = new ReceiptView($door->app, new SalePoster($door->ledger, new SaleReceipt($door->ledger, $door->statements), new ReceiptChange(), new SaleTicketAttach($door->statements), $this->reversal($door->ledger, $door->statements)), new JournalCash($door->pdo), new ReceiptChange(), $this->parties($door));
        $this->capture(function () use ($door, $sale, $poster, $view): void {
            $this->pay($door, $sale, $poster, $view)->returnItem(MallDoor::SLUG, MallDoor::JOURNAL);
        });
        $this->capture(function () use ($door, $poster, $view): void {
            (new PosPayController(
                $door->app,
                $this->gate($door),
                $this->page($door, $door->statements, new TicketDrafts($door->statements)),
                $this->split($door),
                $poster,
                $door->mall,
                $this->checkout($door),
                $view,
            ))->returnItem(MallDoor::SLUG, MallDoor::JOURNAL);
        });

        $door = $this->door('POST', '/' . MallDoor::SLUG . '/pos/pay', [], [
            'csrf' => 'csrf-token',
            'tender_cash' => '1.00',
        ], 'application/json');
        $drafted = new RoutedStatements($this->createStub(PDO::class));
        $drafted->boundRoutes[] = ['match' => 'ticket_draft_lines', 'rows' => [['present' => 1]]];
        $this->capture(function () use ($door, $drafted): void {
            $this->pay($door, null, null, null, new TicketDrafts($drafted))->pay(MallDoor::SLUG);
        });

        $door = $this->door('POST', '/' . MallDoor::SLUG . '/pos/receipt/' . MallDoor::JOURNAL . '/return', [], [
            'csrf' => 'csrf-token',
            'item_id' => 'nope',
        ], 'application/json');
        $this->capture(function () use ($door): void {
            $this->pay($door)->returnItem(MallDoor::SLUG, MallDoor::JOURNAL);
        });

        $door = $this->door('GET', '/' . MallDoor::SLUG . '/pos/gift-certificate.json', ['number' => '9', 'kind' => 'gift_card'], []);
        $this->capture(function () use ($door): void {
            (new PosLookupController($door->app, $this->gate($door), new ScanSearch($door->statements), new GiftIssue($door->statements, $door->ledger)))->giftCertificate(MallDoor::SLUG);
        });
    }

    public function testAReceiptShowsAWaiverAndACashRound(): void
    {
        $door = $this->door('GET', '/' . MallDoor::SLUG . '/pos/receipt/' . MallDoor::JOURNAL, [], []);
        $this->capture(function () use ($door): void {
            $this->receipts($door, $door->ledger)->show(MallDoor::SLUG, MallDoor::JOURNAL, null);
        });

        $sale = $this->saleLedger();
        $statements = new RoutedStatements($this->createStub(PDO::class));
        $statements->boundRoutes[] = ['match' => 'tax_waiver_reason', 'rows' => [[
            'discount_reason' => '',
            'tax_waiver_reason' => 'resale',
        ]]];
        $statements->boundRoutes[] = ['match' => 'role.role', 'rows' => [[
            'name' => 'Cash round',
            'debit_minor' => 0,
            'credit_minor' => 5,
            'role' => 'cash_rounding',
        ]]];
        $request = new Request('GET', '/' . MallDoor::SLUG . '/pos/receipt/' . MallDoor::JOURNAL, [], []);
        $door = MallDoor::open($this, $request);
        $app = $this->companyApp($request, $door);
        $html = $this->capture(function () use ($door, $sale, $statements, $app): void {
            $this->receipts($door, $sale, $statements, $app)->show(MallDoor::SLUG, MallDoor::JOURNAL, null);
        });
        self::assertStringContainsString('Tax waived', $html);
        self::assertStringContainsString('cash-round', $html);
    }

    public function testReceiptMailRefusesAndSends(): void
    {
        $logger = $this->createStub(LoggerInterface::class);
        $settings = new RoutedStatements($this->createStub(PDO::class));
        $mailbox = new ReadyMailbox($logger, $settings);
        $mailer = new ReceiptMailer(new ReceiptMailSettings($settings), $mailbox, new ReceiptLetter());
        $receipt = $this->mailReceipt();
        try {
            $mailer->send($receipt, 'not-an-email', 'Gap', [], '');
            self::fail('a bad address must be refused');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('email', $error->getMessage());
        }
        $mailbox->open = false;
        $settings->rowRoutes[] = ['match' => 'receipt_mail_via', 'rows' => [[
            'receipt_mail_via' => 'gmail',
            'gmail_address' => '',
            'gmail_app_password' => '',
        ]]];
        try {
            $mailer->send($receipt, 'ada@example.test', 'Gap', [], '');
            self::fail('gmail must be refused');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Gmail is not set up', $error->getMessage());
        }
        $settings->rowRoutes[0] = ['match' => 'receipt_mail_via', 'rows' => [[
            'receipt_mail_via' => 'server',
            'gmail_address' => '',
            'gmail_app_password' => '',
        ]]];
        try {
            $mailer->send($receipt, 'ada@example.test', 'Gap', [], '');
            self::fail('smtp must be refused');
        } catch (\RuntimeException $error) {
            self::assertSame(OutboundMail::SMTP_NOT_CONFIGURED, $error->getMessage());
        }
        $mailbox->open = true;
        $mailer->send($receipt, 'ada@example.test', 'Gap', ['Gap'], '');

        $door = $this->door('POST', '/' . MallDoor::SLUG . '/pos/receipt/' . MallDoor::JOURNAL . '/email', [], [
            'csrf' => 'csrf-token',
            'email' => 'ada@example.test',
        ]);
        $this->capture(function () use ($door, $mailer): void {
            $this->emailer($door, $door->ledger, $mailer)->email(MallDoor::SLUG, MallDoor::JOURNAL);
        });

        $sale = $this->saleLedger();
        $statements = new RoutedStatements($this->createStub(PDO::class));
        $statements->boundRoutes[] = ['match' => 'tax_waiver_reason', 'rows' => [['discount_reason' => '', 'tax_waiver_reason' => '']]];
        $request = new Request('POST', '/' . MallDoor::SLUG . '/pos/receipt/' . MallDoor::JOURNAL . '/email', [], [
            'csrf' => 'csrf-token',
            'email' => 'ada@example.test',
        ]);
        $door = MallDoor::open($this, $request);
        $app = $this->companyApp($request, $door);
        $this->capture(function () use ($door, $sale, $statements, $mailer, $app): void {
            $this->emailer($door, $sale, $mailer, $statements, $app)->email(MallDoor::SLUG, MallDoor::JOURNAL);
        });

        $mailbox->open = false;
        $request = new Request('POST', '/' . MallDoor::SLUG . '/pos/receipt/' . MallDoor::JOURNAL . '/email', [], [
            'csrf' => 'csrf-token',
            'email' => 'not-an-email',
        ]);
        $door = MallDoor::open($this, $request);
        $app = $this->companyApp($request, $door);
        $html = $this->capture(function () use ($door, $sale, $statements, $mailer, $app): void {
            $this->emailer($door, $sale, $mailer, $statements, $app)->email(MallDoor::SLUG, MallDoor::JOURNAL);
        });
        self::assertNotSame('', $html);

        $mailbox->fail = true;
        $mailbox->open = true;
        $request = new Request('POST', '/' . MallDoor::SLUG . '/pos/receipt/' . MallDoor::JOURNAL . '/email', [], [
            'csrf' => 'csrf-token',
            'email' => 'ada@example.test',
        ]);
        $door = MallDoor::open($this, $request);
        $app = $this->companyApp($request, $door);
        $html = $this->capture(function () use ($door, $sale, $statements, $mailer, $app): void {
            $this->emailer($door, $sale, $mailer, $statements, $app)->email(MallDoor::SLUG, MallDoor::JOURNAL);
        });
        self::assertStringContainsString('could not be sent', $html);
    }

    public function testSaleMathAndTicketGuards(): void
    {
        $change = new ReceiptChange();
        try {
            $change->accept(100, 50, 50, 0, 10);
            self::fail('short tenders must be refused');
        } catch (DatabaseRefusal) {
        }
        try {
            $change->accept(100, 100, 100, 0, 50);
            self::fail('short cash must be refused');
        } catch (DatabaseRefusal) {
        }

        $ledger = $this->saleLedger();
        $lines = SaleJournal::sale($ledger, [[
            'vendor_id' => MallDoor::VENDOR,
            'placement' => 'store',
            'price' => 500,
            'quantity' => 2,
            'commission' => 0,
            'tax' => 0,
            'discount' => 100,
            'store_discount' => 0,
            'cost' => 40,
        ]], [PostedLines::entry(MallDoor::ACCOUNT, 800, 0, null, null)], 800, 0, 800);
        self::assertNotSame([], $lines);

        $bare = $this->createStub(LedgerStore::class);
        $bare->method('roleAccount')->willReturn(MallDoor::ACCOUNT);
        $poster = new SalePoster($bare, new SaleReceipt($bare, new RoutedStatements($this->createStub(PDO::class))), new ReceiptChange(), new SaleTicketAttach(new RoutedStatements($this->createStub(PDO::class))), $this->reversal($bare, new RoutedStatements($this->createStub(PDO::class))));
        try {
            $poster->pay(MallDoor::DRAWER, '2026-11-12', 0, [
                'ticket_id' => self::TICKET,
                'posting_key' => '',
                'lines' => [],
                'due' => 0,
            ], ['lines' => [], 'sum' => 0, 'check' => null, 'cash' => 0], null);
            self::fail('an empty ticket must be refused');
        } catch (DatabaseRefusal) {
        }
        try {
            $poster->number(MallDoor::JOURNAL);
            self::fail('a missing journal must be refused');
        } catch (DatabaseRefusal) {
        }

        $statements = new RoutedStatements($this->createStub(PDO::class));
        $statements->boundRoutes[] = ['match' => 'SELECT posting_key', 'rows' => [['posting_key' => 'ticket-key']]];
        $statements->boundRoutes[] = ['match' => 'status = ?', 'rows' => [['id' => self::TICKET]]];
        $statements->boundRoutes[] = ['match' => 'count(*) AS lines', 'rows' => [['lines' => 0]]];
        $statements->rowRoutes[] = ['match' => 'tax_bps', 'rows' => [['tax_bps' => 0, 'cash_round_minor' => 0]]];
        $checkout = new SaleCheckout(
            new CheckoutBasket(new TicketStore(
                $statements,
                new TicketPricing($statements),
                new TicketBuyer($statements, $this->mall($statements)),
                new TicketQueue($statements, new TicketPricing($statements)),
                new TicketCustomer($statements, new CustomerStore($statements, new PartyIdentity($statements))),
                new SaleCeiling($statements, $this->actor(), new AuditLog($statements), new Request('GET', '/', [], [])),
                new TicketPriceChange($statements, new TicketPricing($statements), $this->actor(), new AuditLog($statements)),
            ), new TicketTenders($statements, $ledger, new RegisterStore($statements, new MallClock($this->mall($statements)), new RegisterActivity($statements, new MallClock($this->mall($statements)))), new PayoutStore($this->createStub(PDO::class), $statements, new PayoutRunStore($statements, new OccupiedPack($statements)), new PayoutWindow()), $this->mall($statements)), new LayawayBook($statements, $ledger), new TicketDrafts($statements)),
            $this->split($this->door('GET', '/', [], [])),
            $poster,
            $this->mall($statements),
            new SaleHold($this->door('GET', '/', [], [])->session),
            new TicketCustomer($statements, new CustomerStore($statements, new PartyIdentity($statements))),
        );
        try {
            $checkout->pay(MallDoor::SLUG, MallDoor::DRAWER, new Request('POST', '/', [], ['posting_key' => 'other-key']));
            self::fail('a mismatched key must be refused');
        } catch (DatabaseRefusal) {
        }

        $cash = new RoutedStatements($this->createStub(PDO::class));
        $cash->boundRoutes[] = ['match' => 'SELECT id FROM tickets WHERE session_id', 'rows' => [['id' => self::TICKET]]];
        $cash->boundRoutes[] = ['match' => 'count(*) AS lines', 'rows' => [['lines' => 1]]];
        $cash->boundRoutes[] = ['match' => 'SELECT posting_key', 'rows' => [['posting_key' => 'ticket-key']]];
        $cash->boundRoutes[] = ['match' => 'layaway_deposit', 'rows' => [['balance' => 0]]];
        $cash->rowRoutes[] = ['match' => 'mall_sales', 'rows' => []];
        $cash->rowRoutes[] = ['match' => 'SELECT time_zone FROM mall', 'rows' => [['time_zone' => 'America/Los_Angeles']]];
        $cash->rowRoutes[] = ['match' => 'tax_bps', 'rows' => [['tax_bps' => 0, 'cash_round_minor' => 0]]];
        $checkout = new SaleCheckout(
            new CheckoutBasket($this->store($cash, $ledger), new TicketTenders($cash, $ledger, new RegisterStore($cash, new MallClock($this->mall($cash)), new RegisterActivity($cash, new MallClock($this->mall($cash)))), new PayoutStore($this->createStub(PDO::class), $cash, new PayoutRunStore($cash, new OccupiedPack($cash)), new PayoutWindow()), $this->mall($cash)), new LayawayBook($cash, $ledger), new TicketDrafts($cash)),
            new TenderSplit($ledger, new RegisterStore($cash, new MallClock($this->mall($cash)), new RegisterActivity($cash, new MallClock($this->mall($cash)))), new PayoutStore($this->createStub(PDO::class), $cash, new PayoutRunStore($cash, new OccupiedPack($cash)), new PayoutWindow()), $this->mall($cash)),
            $poster,
            $this->mall($cash),
            new SaleHold($this->door('GET', '/', [], [])->session),
            new TicketCustomer($cash, new CustomerStore($cash, new PartyIdentity($cash))),
        );
        try {
            $checkout->pay(MallDoor::SLUG, MallDoor::DRAWER, new Request('POST', '/', [], ['tender_cash' => '1.00', 'posting_key' => 'ticket-key']));
            self::fail('an empty item list must be refused');
        } catch (DatabaseRefusal) {
        }

        $attach = new SaleTicketAttach($statements);
        try {
            $attach->saleKey(self::TICKET, 'other');
            self::fail('a stored key mismatch must be refused');
        } catch (DatabaseRefusal) {
        }
        try {
            (new SaleTicketAttach(new RoutedStatements($this->createStub(PDO::class))))->attach(MallDoor::JOURNAL, self::TICKET, []);
            self::fail('an unlocked ticket must be refused');
        } catch (DatabaseRefusal) {
        }
        $locked = new RoutedStatements($this->createStub(PDO::class));
        $locked->boundRoutes[] = ['match' => 'FOR UPDATE', 'rows' => [['id' => self::TICKET]]];
        $locked->affectedCount = 0;
        try {
            (new SaleTicketAttach($locked))->attach(MallDoor::JOURNAL, self::TICKET, []);
            self::fail('a missed update must be refused');
        } catch (DatabaseRefusal) {
        }
        $mismatch = new RoutedStatements($this->createStub(PDO::class));
        $mismatch->boundRoutes[] = ['match' => 'FOR UPDATE', 'rows' => [['id' => self::TICKET]]];
        $mismatch->boundRoutes[] = ['match' => 'FROM ticket_lines', 'rows' => []];
        try {
            (new SaleTicketAttach($mismatch))->attach(MallDoor::JOURNAL, self::TICKET, [[
                'line_no' => 1,
                'item_id' => self::ITEM,
                'price' => 1,
                'quantity' => 1,
                'commission' => 0,
                'tax' => 0,
                'discount' => 0,
                'store_discount' => 0,
            ]]);
            self::fail('a changed ticket must be refused');
        } catch (DatabaseRefusal) {
        }
        $changed = new RoutedStatements($this->createStub(PDO::class));
        $changed->boundRoutes[] = ['match' => 'FOR UPDATE', 'rows' => [['id' => self::TICKET]]];
        $changed->boundRoutes[] = ['match' => 'FROM ticket_lines', 'rows' => [[
            'item_id' => self::ITEM,
            'price_minor' => 2,
            'quantity' => 1,
        ]]];
        try {
            (new SaleTicketAttach($changed))->attach(MallDoor::JOURNAL, self::TICKET, [[
                'line_no' => 1,
                'item_id' => self::ITEM,
                'price' => 1,
                'quantity' => 1,
                'commission' => 0,
                'tax' => 0,
                'discount' => 0,
                'store_discount' => 0,
            ]]);
            self::fail('a price mismatch must be refused');
        } catch (DatabaseRefusal) {
        }

        $reversal = $this->reversal($doorLedger = $this->door('GET', '/', [], [])->ledger, new RoutedStatements($this->createStub(PDO::class)));
        try {
            $reversal->voidSale(MallDoor::DRAWER, '2026-11-12', MallDoor::JOURNAL);
            self::fail('a missing sale must be refused');
        } catch (DatabaseRefusal) {
        }
        try {
            $reversal->returnItem(MallDoor::DRAWER, '2026-11-12', MallDoor::JOURNAL, self::ITEM, PostedLines::entry(MallDoor::ACCOUNT, 0, 1, null, null), null);
            self::fail('a missing item must be refused');
        } catch (DatabaseRefusal) {
        }
        $sale = $this->saleLedger();
        $sale->method('lines')->willReturn([[
            'account_id' => MallDoor::ACCOUNT,
            'credit_minor' => 100,
            'debit_minor' => 0,
            'subledger_type' => 'vendor',
            'subledger_ref' => MallDoor::VENDOR,
        ]]);
        $this->reversal($sale, new RoutedStatements($this->createStub(PDO::class)))->voidSale(MallDoor::DRAWER, '2026-11-12', MallDoor::JOURNAL);

        $booth = new RoutedStatements($this->createStub(PDO::class));
        $booth->boundRoutes[] = ['match' => 'sale_lines', 'rows' => [[
            'vendor_id' => MallDoor::VENDOR,
            'commission_minor' => 0,
        ]]];
        try {
            (new BoothCorrection($booth, $sale, new SaleCeiling($booth, $this->actor(), new AuditLog($booth), new Request('GET', '/', [], []))))->move('2026-11-12', MallDoor::JOURNAL, self::ITEM, self::TICKET);
            self::fail('a zero commission must be refused');
        } catch (DatabaseRefusal) {
        }

        $gifts = new GiftIssuePosting(new RoutedStatements($this->createStub(PDO::class)), $sale);
        try {
            $gifts->issue(MallDoor::DRAWER, '2026-11-12', '', 100, 'cash', null, 'gift_card', 'nope');
            self::fail('a short token must be refused');
        } catch (DatabaseRefusal) {
        }
        $issued = new RoutedStatements($this->createStub(PDO::class));
        $issued->boundRoutes[] = ['match' => 'posting_key', 'rows' => [['id' => MallDoor::JOURNAL]]];
        self::assertSame(MallDoor::JOURNAL, (new GiftIssuePosting($issued, $sale))->issue(MallDoor::DRAWER, '2026-11-12', '', 100, 'cash', null, 'gift_card', str_repeat('ab', 16)));

        $sync = new SnapSync(new RoutedStatements($this->createStub(PDO::class)));
        try {
            $sync->sync('nope');
            self::fail('a bad snap key must be refused');
        } catch (DatabaseRefusal) {
        }
        self::assertNull($sync->sync(self::TICKET));
        self::assertSame('', $sync->refusal(self::TICKET));
        $door = $this->door('POST', '/' . MallDoor::SLUG . '/pos/sync', [], ['csrf' => 'csrf-token', 'posting_key' => self::TICKET]);
        $this->capture(function () use ($door): void {
            (new SnapController($door->app, $this->gate($door), new SnapSync($door->statements)))->sync(MallDoor::SLUG);
        });

        $buyers = new TicketBuyer(new RoutedStatements($this->createStub(PDO::class)), $this->mall(new RoutedStatements($this->createStub(PDO::class))));
        try {
            $buyers->save('', MallDoor::VENDOR);
            self::fail('an empty ticket must be refused');
        } catch (DatabaseRefusal) {
        }
        $quiet = new RoutedStatements($this->createStub(PDO::class));
        $quiet->affectedCount = 0;
        try {
            (new TicketBuyer($quiet, $this->mall($quiet)))->clear(self::TICKET);
            self::fail('a missed buyer clear must be refused');
        } catch (DatabaseRefusal) {
        }

        $customers = new TicketCustomer(new RoutedStatements($this->createStub(PDO::class)), new CustomerStore(new RoutedStatements($this->createStub(PDO::class)), new PartyIdentity(new RoutedStatements($this->createStub(PDO::class)))));
        try {
            $customers->save('', 'Ada', '', '');
            self::fail('an empty customer ticket must be refused');
        } catch (DatabaseRefusal) {
        }
        $quiet->rowRoutes[] = ['match' => 'INSERT INTO parties', 'rows' => [['id' => MallDoor::PARTY]]];
        try {
            (new TicketCustomer($quiet, new CustomerStore($quiet, new PartyIdentity($quiet))))->save(self::TICKET, 'Ada', '', '');
            self::fail('a missed customer write must be refused');
        } catch (DatabaseRefusal) {
        }
        try {
            (new TicketCustomer($quiet, new CustomerStore($quiet, new PartyIdentity($quiet))))->clear(self::TICKET);
            self::fail('a missed customer clear must be refused');
        } catch (DatabaseRefusal) {
        }

        $drafts = new TicketDrafts($this->routed([['match' => 'ticket_draft_lines', 'rows' => [[
            'id' => self::TICKET,
            'draft_no' => 1,
            'vendor_id' => MallDoor::VENDOR,
            'vendor_label' => 'Ada',
            'description' => 'Hat',
            'price_text' => '1.00',
            'brand_id' => '',
            'brand_label' => '',
            'discount_kind' => 'none',
            'discount_value' => '',
            'attributes_json' => 'true',
        ]]]]));
        try {
            $drafts->forSession(MallDoor::DRAWER);
            self::fail('a scalar attribute map must be refused');
        } catch (DatabaseRefusal) {
        }

        $lines = new TicketLines($quiet, new TicketPricing($quiet));
        try {
            $lines->quantity('', 1, 1);
            self::fail('an empty ticket must be refused');
        } catch (DatabaseRefusal) {
        }
        try {
            $lines->quantity(self::TICKET, 1, 1);
            self::fail('a missed quantity must be refused');
        } catch (DatabaseRefusal) {
        }
        try {
            $lines->discount('', 1, null, null, null);
            self::fail('an empty discount ticket must be refused');
        } catch (DatabaseRefusal) {
        }
        try {
            $lines->discount(self::TICKET, 1, null, null, null);
            self::fail('a missed discount must be refused');
        } catch (DatabaseRefusal) {
        }

        $prices = new RoutedStatements($this->createStub(PDO::class));
        $prices->affectedCount = 0;
        $prices->boundRoutes[] = ['match' => 'floor_minor', 'rows' => [['item_id' => self::ITEM, 'floor_minor' => null]]];
        try {
            (new TicketPriceChange($prices, new TicketPricing($prices), $this->actor(), new AuditLog($prices)))->apply(self::TICKET, 1, 100);
            self::fail('a missed price must be refused');
        } catch (DatabaseRefusal) {
        }
        $floor = new RoutedStatements($this->createStub(PDO::class));
        $floor->boundRoutes[] = ['match' => 'floor_minor', 'rows' => [['item_id' => self::ITEM, 'floor_minor' => 500]]];
        try {
            (new TicketPriceChange($floor, new TicketPricing($floor), $this->actor(), new AuditLog($floor)))->apply(self::TICKET, 1, 100);
            self::fail('a price under the floor must be refused');
        } catch (DatabaseRefusal) {
        }

        $queue = new TicketQueue(new RoutedStatements($this->createStub(PDO::class)), new TicketPricing(new RoutedStatements($this->createStub(PDO::class))));
        try {
            $queue->park(MallDoor::DRAWER, str_repeat('A', 41));
            self::fail('a long park name must be refused');
        } catch (\InvalidArgumentException) {
        }
        $named = new RoutedStatements($this->createStub(PDO::class));
        $named->affectedCount = 0;
        $named->boundRoutes[] = ['match' => 'AS label', 'rows' => [['label' => str_repeat('B', 50)]]];
        try {
            (new TicketQueue($named, new TicketPricing($named)))->park(MallDoor::DRAWER, '');
            self::fail('a missed park must be refused');
        } catch (DatabaseRefusal) {
        }
        $open = new RoutedStatements($this->createStub(PDO::class));
        $open->boundRoutes[] = ['match' => 'status = ?', 'rows' => [['id' => self::TICKET]]];
        try {
            (new TicketQueue($open, new TicketPricing($open)))->recall(MallDoor::DRAWER, self::TICKET);
            self::fail('an open ticket blocks recall');
        } catch (DatabaseRefusal) {
        }
        $open->boundRoutes = [];
        $open->affectedCount = 0;
        try {
            (new TicketQueue($open, new TicketPricing($open)))->recall(MallDoor::DRAWER, self::TICKET);
            self::fail('a missed recall must be refused');
        } catch (DatabaseRefusal) {
        }
        try {
            (new TicketQueue(new RoutedStatements($this->createStub(PDO::class)), new TicketPricing(new RoutedStatements($this->createStub(PDO::class)))))->layaways();
            self::fail('a mall without tax must be refused');
        } catch (DatabaseRefusal) {
        }

        $book = new LayawayBook(new RoutedStatements($this->createStub(PDO::class)), $sale);
        $bookStatements = new RoutedStatements($this->createStub(PDO::class));
        $bookStatements->boundRoutes[] = ['match' => 'AS balance', 'rows' => [['balance' => 0]]];
        $bookStatements->boundRoutes[] = ['match' => 'chart_roles', 'rows' => [['role' => 'gift_card']]];
        try {
            (new LayawayBook($bookStatements, $sale))->hold(MallDoor::DRAWER, '2026-11-12', self::TICKET, 500, [
                'lines' => [PostedLines::entry(MallDoor::ACCOUNT, 100, 0, null, null)],
                'sum' => 100,
                'check' => null,
                'cash' => 0,
            ]);
            self::fail('a gift tender must be refused');
        } catch (DatabaseRefusal) {
        }
        $cashBook = new RoutedStatements($this->createStub(PDO::class));
        $cashBook->boundRoutes[] = ['match' => 'AS balance', 'rows' => [['balance' => 0]]];
        $cashBook->boundRoutes[] = ['match' => 'chart_roles', 'rows' => [['role' => 'cash']]];
        $cashBook->boundRoutes[] = ['match' => 'AS taken', 'rows' => [['taken' => 0]]];
        $cashBook->affectedCount = 0;
        try {
            (new LayawayBook($cashBook, $sale))->hold(MallDoor::DRAWER, '2026-11-12', self::TICKET, 500, [
                'lines' => [PostedLines::entry(MallDoor::ACCOUNT, 100, 0, null, null)],
                'sum' => 100,
                'check' => null,
                'cash' => 100,
            ]);
            self::fail('a missed layaway update must be refused');
        } catch (DatabaseRefusal) {
        }
        try {
            (new LayawaySlip(new RoutedStatements($this->createStub(PDO::class)), new TicketPricing(new RoutedStatements($this->createStub(PDO::class))), new LayawayBook(new RoutedStatements($this->createStub(PDO::class)), $sale)))->read('not-a-uuid');
            self::fail('a bad layaway id must be refused');
        } catch (DatabaseRefusal) {
        }
        unset($book, $doorLedger);
    }

    public function testCustomersSaveAndAnEmptyDirectory(): void
    {
        $door = $this->door('GET', '/' . MallDoor::SLUG . '/customers', [], []);
        $directory = new RoutedStatements($this->createStub(PDO::class));
        $html = $this->capture(function () use ($door, $directory): void {
            (new CustomerController($door->app, new CustomerDirectory($directory), new CustomerStore($directory, new PartyIdentity($directory)), new SellerPaths($door->mall)))->show(MallDoor::SLUG);
        });
        self::assertStringContainsString('No customers yet.', $html);

        $statements = new RoutedStatements($this->createStub(PDO::class));
        $statements->boundRoutes[] = ['match' => "status = 'active'", 'rows' => [['present' => 1]]];
        $statements->boundRoutes[] = ['match' => 'FROM persons', 'rows' => [['party_id' => MallDoor::PARTY]]];
        $store = new CustomerStore($statements, new PartyIdentity($statements));
        $door = $this->door('POST', '/' . MallDoor::SLUG . '/customers/' . MallDoor::PARTY, [], [
            'csrf' => 'csrf-token',
            'customer_name' => 'Ada Lovelace',
            'customer_phone' => '',
            'customer_email' => 'ada@example.test',
        ]);
        $this->capture(function () use ($door, $store, $statements): void {
            (new CustomerController($door->app, new CustomerDirectory($statements), $store, new SellerPaths($door->mall)))->save(MallDoor::SLUG, MallDoor::PARTY);
        });

        $missing = new RoutedStatements($this->createStub(PDO::class));
        $door = $this->door('POST', '/' . MallDoor::SLUG . '/customers/' . MallDoor::PARTY, [], [
            'csrf' => 'csrf-token',
            'customer_name' => 'Ada',
            'customer_phone' => '',
            'customer_email' => 'ada@example.test',
        ]);
        $html = $this->capture(function () use ($door, $missing): void {
            (new CustomerController($door->app, new CustomerDirectory($missing), new CustomerStore($missing, new PartyIdentity($missing)), new SellerPaths($door->mall)))->save(MallDoor::SLUG, MallDoor::PARTY);
        });
        self::assertStringContainsString('ada@example.test', $html);

        $door = $this->door('POST', '/' . MallDoor::SLUG . '/customers/' . MallDoor::PARTY . '/vendor-role', [], [
            'csrf' => 'csrf-token',
            'code' => 'ADA',
        ]);
        $this->capture(function () use ($door, $missing): void {
            (new CustomerController($door->app, new CustomerDirectory($missing), new CustomerStore($missing, new PartyIdentity($missing)), new SellerPaths($door->mall)))->addVendorRole(MallDoor::SLUG, MallDoor::PARTY);
        });
    }

    public function testAJsonReturnReportsABadQuantityAndAPostedReturn(): void
    {
        $sale = $this->saleLedger();
        $bad = new RoutedStatements($this->createStub(PDO::class));
        $bad->boundRoutes[] = ['match' => 'sale.quantity', 'rows' => [[
            'item_id' => self::ITEM,
            'price_minor' => 100,
            'quantity' => 'nope',
            'commission_minor' => 0,
            'tax_minor' => 0,
            'discount_minor' => 0,
            'store_discount_minor' => 0,
            'offer_name' => '',
            'code' => 'HAT',
            'description' => 'Hat',
            'vendor_name' => 'Ada',
            'tax_exempt' => false,
            'returned' => false,
        ]]];
        $poster = new SalePoster($sale, new SaleReceipt($sale, $bad), new ReceiptChange(), new SaleTicketAttach($bad), $this->reversal($sale, $bad));
        $door = $this->door('POST', '/' . MallDoor::SLUG . '/pos/receipt/' . MallDoor::JOURNAL . '/return', [], [
            'csrf' => 'csrf-token',
            'item_id' => self::ITEM,
            'return_tender' => 'cash',
        ], 'application/json');
        $json = $this->capture(function () use ($door, $sale, $poster): void {
            $this->pay($door, $sale, $poster)->returnItem(MallDoor::SLUG, MallDoor::JOURNAL);
        });
        self::assertStringContainsString('expected', $json);

        $posted = new RoutedStatements($this->createStub(PDO::class));
        $line = [
            'item_id' => self::ITEM,
            'price_minor' => 100,
            'quantity' => 1,
            'commission_minor' => 0,
            'tax_minor' => 0,
            'discount_minor' => 0,
            'store_discount_minor' => 0,
            'offer_name' => '',
            'code' => 'HAT',
            'description' => 'Hat',
            'vendor_name' => 'Ada',
            'vendor_id' => MallDoor::VENDOR,
            'placement' => 'consigned',
            'cost_minor' => 0,
            'tax_exempt' => false,
            'returned' => false,
        ];
        $posted->boundRoutes[] = ['match' => 'sale.item_id', 'rows' => [$line]];
        $posted->boundRoutes[] = ['match' => 'item.placement', 'rows' => [$line]];
        $posted->boundRoutes[] = ['match' => 'AS total', 'rows' => [['total' => 0]]];
        $posted->boundRoutes[] = ['match' => 'line.debit_minor > 0', 'rows' => [['account_id' => MallDoor::ACCOUNT]]];
        $ready = new SalePoster($sale, new SaleReceipt($sale, $posted), new ReceiptChange(), new SaleTicketAttach($posted), $this->reversal($sale, $posted));
        $again = $this->door('POST', '/' . MallDoor::SLUG . '/pos/receipt/' . MallDoor::JOURNAL . '/return', [], [
            'csrf' => 'csrf-token',
            'item_id' => self::ITEM,
            'return_tender' => 'cash',
        ], 'application/json');
        $done = $this->capture(function () use ($again, $sale, $ready): void {
            $this->pay($again, $sale, $ready)->returnItem(MallDoor::SLUG, MallDoor::JOURNAL);
        });
        self::assertStringContainsString('Item returned.', $done);
    }

    /**
     * @param list<array{match: string, rows: list<array<string, mixed>>}> $routes
     */
    private function routed(array $routes): RoutedStatements
    {
        $statements = new RoutedStatements($this->createStub(PDO::class));
        $statements->boundRoutes = $routes;

        return $statements;
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     */
    private function door(string $method, string $path, array $query, array $post, string $accept = ''): MallDoor
    {
        return MallDoor::open($this, new Request($method, $path, $query, $post, '', '', '', [], $accept));
    }

    private function page(MallDoor $door, \PerfectConsign\Shared\Database\Statements $statements, TicketDrafts $drafts, ?LedgerStore $ledger = null): PosPage
    {
        $ledger ??= $door->ledger;
        $pricing = new TicketPricing($statements);
        $ceiling = new SaleCeiling($statements, $door->actor, new AuditLog($statements), $door->app->request());
        $tickets = $this->store($statements, $ledger, $ceiling);
        $runs = new PayoutRunStore($statements, new OccupiedPack($statements));
        $payouts = new PayoutStore($door->pdo, $statements, $runs, new PayoutWindow());
        $clock = new MallClock($door->mall);
        $register = new PosRegister(
            $tickets,
            new TicketTenders($statements, $door->ledger, new RegisterStore($statements, $clock, new RegisterActivity($statements, $clock)), $payouts, $door->mall),
            $drafts,
            new RegisterGate($door->app, $door->register, $tickets),
            new LayawayBook($statements, $door->ledger),
            new SnapSync($statements),
        );
        $hold = new SaleHold($door->session);

        return new PosPage($door->app, $register, new PosCartView(
            $door->app,
            $register,
            new GiftIssue($statements, $door->ledger),
            $hold,
            new SalePoster($ledger, new SaleReceipt($ledger, $statements), new ReceiptChange(), new SaleTicketAttach($statements), $this->reversal($ledger, $statements)),
            $payouts,
        ), $hold);
    }

    private function store(\PerfectConsign\Shared\Database\Statements $statements, LedgerStore $ledger, ?SaleCeiling $ceiling = null): TicketStore
    {
        $pricing = new TicketPricing($statements);
        $mall = $statements instanceof RoutedStatements ? $this->mall($statements) : new MallSettings($statements, new CompanyStore($statements, new DatabaseRole($this->createStub(PDO::class)), $this->createStub(PDO::class), new CompanyLines()), new MallDay($statements));

        return new TicketStore(
            $statements,
            $pricing,
            new TicketBuyer($statements, $mall),
            new TicketQueue($statements, $pricing),
            new TicketCustomer($statements, new CustomerStore($statements, new PartyIdentity($statements))),
            $ceiling ?? new SaleCeiling($statements, $this->actor(), new AuditLog($statements), new Request('GET', '/', [], [])),
            new TicketPriceChange($statements, $pricing, $this->actor(), new AuditLog($statements)),
        );
    }

    private function mall(RoutedStatements $statements): MallSettings
    {
        if ($statements->rowRoutes === []) {
            $statements->rowRoutes[] = ['match' => 'booth_width_ft', 'rows' => [[
                'time_zone' => 'America/Los_Angeles',
                'booth_width_ft' => 10,
                'booth_depth_ft' => 10,
                'floor_width_ft' => 40,
                'floor_depth_ft' => 40,
                'commission_bps' => 4000,
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
            $statements->rowRoutes[] = ['match' => 'time_zone', 'rows' => [['time_zone' => 'America/Los_Angeles']]];
        }

        return new MallSettings(
            $statements,
            new CompanyStore($statements, new DatabaseRole($this->createStub(PDO::class)), $this->createStub(PDO::class), new CompanyLines()),
            new MallDay($statements),
        );
    }

    private function companyApp(Request $request, MallDoor $door): MallApp
    {
        $statements = new RoutedStatements($this->createStub(PDO::class));
        $statements->rowRoutes[] = ['match' => 'booth_width_ft', 'rows' => [[
            'time_zone' => 'America/Los_Angeles',
            'booth_width_ft' => 10,
            'booth_depth_ft' => 10,
            'floor_width_ft' => 40,
            'floor_depth_ft' => 40,
            'commission_bps' => 4000,
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
        $statements->rowRoutes[] = ['match' => 'street', 'rows' => [[
            'street' => '1 Main',
            'street2' => '',
            'city' => 'Bend',
            'state' => 'OR',
            'postal' => '97701',
            'phone' => '',
            'email' => '',
        ]]];
        $statements->rowRoutes[] = ['match' => 'SELECT time_zone FROM mall', 'rows' => [['time_zone' => 'America/Los_Angeles']]];
        $view = new View(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src');
        $tenants = new TenantLookup($door->pdo);
        $logger = $this->createStub(LoggerInterface::class);
        $missing = new MissingPage($door->session, $logger, $view, $request, $tenants);
        $sessions = new LoginSession($door->session);
        $mall = new MallSettings(
            $statements,
            new CompanyStore($statements, new DatabaseRole($door->pdo), $door->pdo, new CompanyLines()),
            new MallDay($statements),
        );

        return new MallApp(
            $door->pdo,
            $door->session,
            $view,
            $request,
            new OwnerAccess(),
            $mall,
            new ActiveRegister($door->pdo, $door->session, $mall, new MallClock($mall)),
            $tenants,
            new FrontDoor(
                $missing,
                new PriorClose($door->session, $request),
                new OwnerGate(
                    $door->pdo,
                    $door->session,
                    $logger,
                    $request,
                    $tenants,
                    $missing,
                    new StaffSignIn($door->pdo, $logger, $sessions, new SignInFailureRetention($door->pdo)),
                    $sessions,
                ),
                new PostedRefusal($logger),
                new Actor($door->session),
                new CredentialStamp($door->pdo, $door->session, new DatabaseRole($door->pdo)),
            ),
        );
    }

    private function parties(MallDoor $door): PartyStore
    {
        return new PartyStore($door->statements, new JournalAudit($door->actor, new AuditLog($door->statements)), new AssignmentBook($door->statements));
    }

    private function actor(): \PerfectConsign\Bootstrap\Actor
    {
        return new \PerfectConsign\Bootstrap\Actor($this->door('GET', '/', [], [])->session);
    }

    private function pos(MallDoor $door, TicketDrafts $drafts): PosController
    {
        $page = $this->page($door, $door->statements, new TicketDrafts($door->statements));

        return new PosController(
            $door->app,
            $page,
            $this->store($door->statements, $door->ledger),
            $this->gate($door),
            new SaleHold($door->session),
            $this->createStub(LoggerInterface::class),
            $drafts,
            new DraftAwareDiscard($this->store($door->statements, $door->ledger), $drafts),
        );
    }

    private function gate(MallDoor $door): RegisterGate
    {
        return new RegisterGate($door->app, $door->register, $this->store($door->statements, $door->ledger));
    }

    private function adds(MallDoor $door, ?LedgerStore $ledger = null): PosAddController
    {
        $statements = new RoutedStatements($this->createStub(PDO::class));
        $mall = $this->mall($statements);
        $pricing = new TicketPricing($statements);
        $tickets = $this->store($statements, $door->ledger);
        $item = new InstantItem(
            new ItemWriter(new IntakeCatalog($statements), new NameListStore($statements, new CatalogLinks($statements)), $mall, new StoreTake()),
            new InventoryStore($statements, new CatalogLinks($statements), $mall),
            $tickets,
            new TicketLines($statements, $pricing),
            new TicketDrafts($statements),
            new SaleCeiling($statements, $door->actor, new AuditLog($statements), $door->app->request()),
            $mall,
        );

        return new PosAddController($door->app, $door->mall, $this->gate($door), $this->page($door, $door->statements, new TicketDrafts($door->statements), $ledger), $item, new SaleHold($door->session));
    }

    private function buyer(MallDoor $door, CartStatements $bad): PosBuyerController
    {
        return new PosBuyerController(
            $door->app,
            $this->gate($door),
            $this->page($door, $door->statements, new TicketDrafts($door->statements)),
            $this->store($bad, $door->ledger),
            new TicketBuyer($bad, $door->mall),
            new SaleHold($door->session),
        );
    }

    private function lines(MallDoor $door): PosLineController
    {
        return new PosLineController(
            $door->app,
            $this->gate($door),
            $this->page($door, $door->statements, new TicketDrafts($door->statements)),
            $this->store($door->statements, $door->ledger),
            new TicketLines($door->statements, new TicketPricing($door->statements)),
            new SaleHold($door->session),
            new SaleCeiling($door->statements, $door->actor, new AuditLog($door->statements), $door->app->request()),
        );
    }

    private function layaway(MallDoor $door, LayawaySlip $slips): PosLayawayController
    {
        return new PosLayawayController(
            $door->app,
            $door->mall,
            $this->gate($door),
            $this->page($door, $door->statements, new TicketDrafts($door->statements)),
            new LayawayBook($door->statements, $door->ledger),
            $slips,
            new SaleHold($door->session),
        );
    }

    private function tenders(MallDoor $door): PosTenderController
    {
        return new PosTenderController(
            $door->app,
            $door->mall,
            $this->gate($door),
            $this->page($door, $door->statements, new TicketDrafts($door->statements)),
            $this->store($door->statements, $door->ledger),
            new TicketTenders($door->statements, $door->ledger, new RegisterStore($door->statements, new MallClock($door->mall), new RegisterActivity($door->statements, new MallClock($door->mall))), new PayoutStore($door->pdo, $door->statements, new PayoutRunStore($door->statements, new OccupiedPack($door->statements)), new PayoutWindow()), $door->mall),
            new GiftIssue($door->statements, $door->ledger),
            new LayawayBook($door->statements, $door->ledger),
            new SaleHold($door->session),
        );
    }

    private function split(MallDoor $door): TenderSplit
    {
        $clock = new MallClock($door->mall);

        return new TenderSplit(
            $door->ledger,
            new RegisterStore($door->statements, $clock, new RegisterActivity($door->statements, $clock)),
            new PayoutStore($door->pdo, $door->statements, new PayoutRunStore($door->statements, new OccupiedPack($door->statements)), new PayoutWindow()),
            $door->mall,
        );
    }

    private function checkout(MallDoor $door, ?TicketDrafts $drafts = null): SaleCheckout
    {
        $drafts ??= new TicketDrafts($door->statements);

        return new SaleCheckout(
            new CheckoutBasket($this->store($door->statements, $door->ledger), new TicketTenders($door->statements, $door->ledger, new RegisterStore($door->statements, new MallClock($door->mall), new RegisterActivity($door->statements, new MallClock($door->mall))), new PayoutStore($door->pdo, $door->statements, new PayoutRunStore($door->statements, new OccupiedPack($door->statements)), new PayoutWindow()), $door->mall), new LayawayBook($door->statements, $door->ledger), $drafts),
            $this->split($door),
            new SalePoster($door->ledger, new SaleReceipt($door->ledger, $door->statements), new ReceiptChange(), new SaleTicketAttach($door->statements), $this->reversal($door->ledger, $door->statements)),
            $door->mall,
            new SaleHold($door->session),
            new TicketCustomer($door->statements, new CustomerStore($door->statements, new PartyIdentity($door->statements))),
        );
    }

    private function pay(MallDoor $door, ?LedgerStore $ledger = null, ?SalePoster $poster = null, ?ReceiptView $view = null, ?TicketDrafts $drafts = null): PosPayController
    {
        $ledger ??= $door->ledger;
        $poster ??= new SalePoster($ledger, new SaleReceipt($ledger, $door->statements), new ReceiptChange(), new SaleTicketAttach($door->statements), $this->reversal($ledger, $door->statements));
        $view ??= new ReceiptView($door->app, $poster, new JournalCash($door->pdo), new ReceiptChange(), $this->parties($door));

        return new PosPayController(
            $door->app,
            $this->gate($door),
            $this->page($door, $door->statements, new TicketDrafts($door->statements)),
            $this->split($door),
            $poster,
            $door->mall,
            $drafts === null ? $this->checkout($door) : new SaleCheckout(
                new CheckoutBasket($this->store($door->statements, $door->ledger), new TicketTenders($door->statements, $door->ledger, new RegisterStore($door->statements, new MallClock($door->mall), new RegisterActivity($door->statements, new MallClock($door->mall))), new PayoutStore($door->pdo, $door->statements, new PayoutRunStore($door->statements, new OccupiedPack($door->statements)), new PayoutWindow()), $door->mall), new LayawayBook($door->statements, $door->ledger), $drafts),
                $this->split($door),
                $poster,
                $door->mall,
                new SaleHold($door->session),
                new TicketCustomer($door->statements, new CustomerStore($door->statements, new PartyIdentity($door->statements))),
            ),
            $view,
        );
    }

    private function receipts(MallDoor $door, LedgerStore $ledger, ?RoutedStatements $statements = null, ?MallApp $app = null): ReceiptView
    {
        $statements ??= new RoutedStatements($this->createStub(PDO::class));

        return new ReceiptView(
            $app ?? $door->app,
            new SalePoster($ledger, new SaleReceipt($ledger, $statements), new ReceiptChange(), new SaleTicketAttach($statements), $this->reversal($ledger, $statements)),
            new JournalCash($door->pdo),
            new ReceiptChange(),
            $this->parties($door),
        );
    }

    private function emailer(MallDoor $door, LedgerStore $ledger, ReceiptMailer $mailer, ?RoutedStatements $statements = null, ?MallApp $app = null): ReceiptEmailController
    {
        $app ??= $door->app;

        return new ReceiptEmailController($app, new SalePoster($ledger, new SaleReceipt($ledger, $statements ?? $door->statements), new ReceiptChange(), new SaleTicketAttach($statements ?? $door->statements), $this->reversal($ledger, $statements ?? $door->statements)), $this->receipts($door, $ledger, $statements, $app), $mailer);
    }

    private function reversal(LedgerStore $ledger, \PerfectConsign\Shared\Database\Statements $statements): SaleReversal
    {
        return new SaleReversal($ledger, $statements, new SaleCeiling($statements, $this->actor(), new AuditLog($statements), new Request('GET', '/', [], [])), new VendorClawback($ledger, $statements));
    }

    /**
     * @return LedgerStore&Stub
     */
    private function saleLedger(): LedgerStore
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
        $ledger->method('lines')->willReturn([]);
        $ledger->method('periods')->willReturn([]);

        return $ledger;
    }

    /**
     * @return array{sale_number: int, posted_on: string, voided: bool, lines: list<array{description: string, code: string, price: int, quantity: int, discount: int}>, tenders: list<array{name: string, amount: int}>, round: int, discount: int}
     */
    private function mailReceipt(): array
    {
        return [
            'sale_number' => 4,
            'posted_on' => '2026-11-12',
            'voided' => false,
            'lines' => [],
            'tenders' => [],
            'round' => 0,
            'discount' => 0,
        ];
    }
}

final class CartStatements extends RoutedStatements
{
    public bool $badPrice = false;

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(string $sql): array
    {
        if (str_contains($sql, 'mall_sales') || str_contains($sql, 'percent_bps')) {
            return [];
        }
        if (str_contains($sql, 'tax_bps')) {
            return [['tax_bps' => 0, 'cash_round_minor' => 0]];
        }

        return parent::rows($sql);
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    public function bound(string $sql, array $params): array
    {
        if (str_contains($sql, 'count(*) AS lines')) {
            return [['lines' => 1]];
        }
        if (str_contains($sql, 'SELECT id FROM tickets WHERE session_id')) {
            return [['id' => '01900000-0000-7000-8000-0000000000c4']];
        }
        if (str_contains($sql, 'SELECT posting_key FROM tickets WHERE id')) {
            return [['posting_key' => 'ticket-key']];
        }
        if (str_contains($sql, 'line.line_no')) {
            return [[
                'line_no' => 1,
                'item_id' => '01900000-0000-7000-8000-0000000000c3',
                'price_minor' => $this->badPrice ? 'nope' : 100,
                'quantity' => 1,
                'line_discount_minor' => 0,
                'offer_discount_minor' => 0,
                'store_discount_minor' => 0,
                'offer_name' => '',
                'code' => str_repeat('A', 41),
                'description' => '',
                'vendor_id' => MallDoor::VENDOR,
                'placement' => 'consigned',
                'cost_minor' => 0,
                'vendor_name' => '',
                'tax_exempt' => false,
                'line_discount_kind' => '',
                'line_discount_value' => null,
                'line_discount_reason' => '',
                'commission_bps' => 0,
            ]];
        }
        if (str_contains($sql, 'stall_line_discount')) {
            return [['share' => 0]];
        }
        if (str_contains($sql, 'AS balance')) {
            return [['balance' => 0]];
        }
        if (str_contains($sql, 'FROM ticket_draft_lines')) {
            return [[
                'id' => '01900000-0000-7000-8000-0000000000c4',
                'draft_no' => 1,
                'vendor_id' => MallDoor::VENDOR,
                'vendor_label' => 'Ada',
                'description' => 'Hat',
                'price_text' => '1.00',
                'brand_id' => '',
                'brand_label' => '',
                'discount_kind' => 'none',
                'discount_value' => '',
                'attributes_json' => '{}',
            ]];
        }
        if (str_contains($sql, 'AS customer') && $this->badPrice) {
            return [['customer' => 'Ada']];
        }

        return parent::bound($sql, $params);
    }
}
