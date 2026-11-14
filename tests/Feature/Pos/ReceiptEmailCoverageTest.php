<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Pos;

use ArrayObject;
use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\ActiveRegister;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\AuditLog;
use PerfectConsign\Bootstrap\CredentialStamp;
use PerfectConsign\Bootstrap\SaleCeiling;
use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Bootstrap\FrontDoor;
use PerfectConsign\Bootstrap\JournalAudit;
use PerfectConsign\Bootstrap\LoginSession;
use PerfectConsign\Bootstrap\MallApp;
use PerfectConsign\Bootstrap\MissingPage;
use PerfectConsign\Bootstrap\OwnerGate;
use PerfectConsign\Bootstrap\PostedRefusal;
use PerfectConsign\Bootstrap\PriorClose;
use PerfectConsign\Bootstrap\SignInFailureRetention;
use PerfectConsign\Bootstrap\StaffSignIn;
use PerfectConsign\Bootstrap\TenantLookup;
use PerfectConsign\Feature\Company\CompanyLines;
use PerfectConsign\Feature\Company\CompanyStore;
use PerfectConsign\Feature\Mall\MallClock;
use PerfectConsign\Feature\Mall\MallDay;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Feature\Mall\ReceiptMailSettings;
use PerfectConsign\Feature\Party\AssignmentBook;
use PerfectConsign\Feature\Party\PartyStore;
use PerfectConsign\Feature\Payout\VendorClawback;
use PerfectConsign\Feature\Pos\ReceiptChange;
use PerfectConsign\Feature\Pos\ReceiptEmailController;
use PerfectConsign\Feature\Pos\ReceiptLetter;
use PerfectConsign\Feature\Pos\ReceiptMailer;
use PerfectConsign\Feature\Pos\ReceiptView;
use PerfectConsign\Feature\Pos\SalePoster;
use PerfectConsign\Feature\Pos\SaleReceipt;
use PerfectConsign\Feature\Pos\SaleReversal;
use PerfectConsign\Feature\Pos\SaleTicketAttach;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\JournalCash;
use PerfectConsign\Ledger\LedgerPeriods;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Auth\OwnerAccess;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\ResponseSent;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Mail\GmailSender;
use PerfectConsign\Shared\Mail\LetterMailbox;
use PerfectConsign\Shared\Mail\OutboundMail;
use PerfectConsign\Shared\Mail\PlatformOperatorNotice;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ReceiptEmailCoverageTest extends TestCase
{
    private const SALE = '01900000-0000-7000-8000-0000000000c1';

    private const MAIL_ENV = [
        'STALL_MAIL_FILE',
        'STALL_SMTP_HOST',
        'STALL_SMTP_USERNAME',
        'STALL_SMTP_PASSWORD',
        'STALL_SMTP_FROM',
        'STALL_SMTP_PORT',
    ];

    public function testAReceiptEmailRedirectsWhenTheMailboxAcceptsIt(): void
    {
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc2-receipt-mail.txt';
        if (is_file($file)) {
            unlink($file);
        }
        $saved = $this->snapshot();
        try {
            putenv('STALL_MAIL_FILE=' . $file);
            $door = $this->controller($this->request('ada@mall.test'), 'server');
            ob_start();
            try {
                $door['controller']->email('harbor', self::SALE);
                self::fail('the receipt stayed on the page');
            } catch (ResponseSent $sent) {
                self::assertSame('redirect', $sent->getMessage());
            } finally {
                ob_end_clean();
            }
            self::assertSame('Receipt emailed.', $door['bag']['notice'] ?? null);
            $body = is_file($file) ? file_get_contents($file) : false;
            self::assertIsString($body);
            self::assertStringContainsString('Subject: Receipt 41', $body);
        } finally {
            $this->restore($saved);
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testAReceiptEmailShowsTheBooksRefusal(): void
    {
        $html = $this->page('ada@mall.test', 'refuse');

        self::assertStringContainsString('the books refused that post', $html);
        self::assertStringContainsString('Sale', $html);
    }

    public function testAReceiptEmailShowsABadAddress(): void
    {
        $html = $this->page('not-an-email', 'server');

        self::assertStringContainsString('that email is not an email address', $html);
    }

    public function testAReceiptEmailShowsWhenGmailIsNotSetUp(): void
    {
        $html = $this->page('ada@mall.test', 'gmail');

        self::assertStringContainsString('Gmail is not set up', $html);
    }

    public function testAReceiptEmailShowsWhenTheServerMailboxIsDown(): void
    {
        $html = $this->page('ada@mall.test', 'server');

        self::assertStringContainsString('the server mailbox is not set up', $html);
    }

    public function testAReceiptEmailShowsWhenTheSendFails(): void
    {
        $saved = $this->snapshot();
        try {
            putenv('STALL_MAIL_FILE=' . sys_get_temp_dir());
            $html = $this->capture(function (): void {
                $this->controller($this->request('ada@mall.test'), 'server')['controller']->email('harbor', self::SALE);
            });
        } finally {
            $this->restore($saved);
        }

        self::assertStringContainsString('the receipt email could not be sent', $html);
    }

    public function testTheMailerRefusesAnAddressWithALineBreak(): void
    {
        $mailer = $this->mailer('server');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('that email is not an email address');
        $mailer->send($this->letterReceipt(), "ada@mall.test\nextra", 'Harbor', [], '');
    }

    private function page(string $email, string $mode): string
    {
        $saved = $this->snapshot();
        try {
            return $this->capture(function () use ($email, $mode): void {
                $this->controller($this->request($email), $mode)['controller']->email('harbor', self::SALE);
            });
        } finally {
            $this->restore($saved);
        }
    }

    /**
     * @param callable(): void $work
     */
    private function capture(callable $work): string
    {
        ob_start();
        try {
            $work();
        } finally {
            $html = ob_get_clean();
        }

        return is_string($html) ? $html : '';
    }

    /**
     * @return array<string, string|false>
     */
    private function snapshot(): array
    {
        $saved = [];
        foreach (self::MAIL_ENV as $name) {
            $saved[$name] = getenv($name);
            putenv($name);
        }

        return $saved;
    }

    /**
     * @param array<string, string|false> $saved
     */
    private function restore(array $saved): void
    {
        foreach ($saved as $name => $value) {
            if (!is_string($value) || $value === '') {
                putenv($name);
                continue;
            }
            putenv($name . '=' . $value);
        }
    }

    /**
     * @return array{
     *     sale_number: int,
     *     posted_on: string,
     *     voided: bool,
     *     lines: list<array{description: string, code: string, price: int, quantity: int, discount: int}>,
     *     tenders: list<array{name: string, amount: int}>,
     *     round: int,
     *     discount: int
     * }
     */
    private function letterReceipt(): array
    {
        return [
            'sale_number' => 41,
            'posted_on' => '2026-11-12',
            'voided' => false,
            'lines' => [],
            'tenders' => [],
            'round' => 0,
            'discount' => 0,
        ];
    }

    private function request(string $email): Request
    {
        return new Request(
            'POST',
            '/harbor/pos/receipt/' . self::SALE . '/email',
            [],
            ['csrf' => 'csrf-token', 'email' => $email],
            '',
            'https://example.test',
            '203.0.113.10',
        );
    }

    /**
     * @return array{controller: ReceiptEmailController, bag: ArrayObject<string, string>}
     */
    private function controller(Request $request, string $mode): array
    {
        /** @var ArrayObject<string, string> $bag */
        $bag = new ArrayObject([
            'csrf' => 'csrf-token',
            'owner_slug' => 'harbor',
            'actor_role' => 'owner',
            'actor_account' => '01900000-0000-7000-8000-0000000000aa',
            'credentials_version' => '1',
            'owner_name' => 'Ada',
            'signed_in_at' => (string) time(),
            'last_seen_at' => (string) time(),
        ]);
        $session = $this->createStub(Session::class);
        $session->method('get')->willReturnCallback(static function (string $key) use ($bag): mixed {
            return $bag->offsetExists($key) ? $bag->offsetGet($key) : null;
        });
        $session->method('set')->willReturnCallback(static function (string $key, mixed $value) use ($bag): void {
            $bag->offsetSet($key, is_scalar($value) ? (string) $value : '');
        });
        $session->method('has')->willReturnCallback(static function (string $key) use ($bag): bool {
            return $bag->offsetExists($key);
        });
        $session->method('delete')->willReturnCallback(static function (string $key) use ($bag): void {
            $bag->offsetUnset($key);
        });
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willReturn(0);
        $pdo->method('prepare')->willReturnCallback(function (string $sql): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('execute')->willReturn(true);
            $statement->method('fetchAll')->willReturn([]);
            $statement->method('fetch')->willReturn($this->fetched($sql));
            $statement->method('fetchColumn')->willReturn(str_contains($sql, 'platform.owners') ? '1' : false);

            return $statement;
        });
        $logger = $this->createStub(LoggerInterface::class);
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
        $rows = new MallFloorRows($pdo);
        $mall = new MallSettings(
            $rows,
            new CompanyStore($rows, new DatabaseRole($pdo), $pdo, new CompanyLines()),
            new MallDay($rows),
        );
        $app = new MallApp(
            $pdo,
            $session,
            $view,
            $request,
            new OwnerAccess(),
            $mall,
            new ActiveRegister($pdo, $session, $mall, new MallClock($mall)),
            $tenants,
            $door,
        );
        $cash = new JournalCash($pdo);
        $ledger = new LedgerStore($pdo, $rows, new LedgerPeriods($pdo), $cash);
        $actor = new Actor($session);
        $poster = new SalePoster(
            $ledger,
            new SaleReceipt($ledger, $rows),
            new ReceiptChange(),
            new SaleTicketAttach($rows),
            new SaleReversal(
                $ledger,
                $rows,
                new SaleCeiling($rows, $actor, new AuditLog($rows), $request),
                new VendorClawback($ledger, $rows),
            ),
        );

        return [
            'controller' => new ReceiptEmailController(
                $app,
                $poster,
                new ReceiptView($app, $poster, $cash, new ReceiptChange(), new PartyStore(
                    $rows,
                    new JournalAudit($actor, new AuditLog($rows)),
                    new AssignmentBook($rows),
                )),
                $this->mailer($mode),
            ),
            'bag' => $bag,
        ];
    }

    private function mailer(string $mode): ReceiptMailer
    {
        $settings = new ReceiptMailSettings(new ReceiptMailRows($this->createStub(PDO::class), $mode));
        $logger = $this->createStub(LoggerInterface::class);
        $notice = new PlatformOperatorNotice($logger);

        return new ReceiptMailer(
            $settings,
            new LetterMailbox($settings, new OutboundMail($logger), new GmailSender($notice)),
            new ReceiptLetter(),
        );
    }

    /**
     * @return array<string, mixed>|false
     */
    private function fetched(string $sql): array|false
    {
        if (str_contains($sql, 'schema_name')) {
            return [
                'id' => '01900000-0000-7000-8000-0000000000bb',
                'slug' => 'harbor',
                'schema_name' => 'tenant_' . str_repeat('ab', 16),
                'display_name' => 'Harbor',
            ];
        }
        if (str_contains($sql, 'FROM journals WHERE id')) {
            return [
                'id' => self::SALE,
                'journal_no' => 41,
                'posting_date' => '2026-11-12',
                'posting_key' => 'sale:' . self::SALE,
                'source_type' => 'sale',
                'description' => 'Sale',
                'is_reversal' => true,
            ];
        }

        return false;
    }
}

final class MallFloorRows extends Statements
{
    public function rows(string $sql): array
    {
        if (str_contains($sql, 'FROM vendors')) {
            return [];
        }

        return [[
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
            'street' => '',
            'street2' => '',
            'city' => '',
            'state' => '',
            'postal' => '',
            'phone' => '',
            'email' => '',
        ]];
    }

    /**
     * @param list<mixed> $params
     */
    public function bound(string $sql, array $params): array
    {
        return [];
    }
}

final class ReceiptMailRows extends Statements
{
    public function __construct(PDO $pdo, private readonly string $mode)
    {
        parent::__construct($pdo);
    }

    public function rows(string $sql): array
    {
        if ($this->mode === 'refuse') {
            throw new DatabaseRefusal('the books refused that post');
        }

        return [[
            'receipt_mail_via' => $this->mode === 'gmail' ? 'gmail' : 'server',
            'gmail_address' => '',
            'gmail_app_password' => null,
        ]];
    }
}
