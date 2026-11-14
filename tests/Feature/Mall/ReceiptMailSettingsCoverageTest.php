<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Mall;

use PerfectConsign\Feature\Mall\ReceiptMailSettings;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Mail\SealedSecret;
use PDO;
use PHPUnit\Framework\TestCase;

final class ReceiptMailSettingsCoverageTest extends TestCase
{
    public function testViaAndAddressAndPasswordFollowTheMallRow(): void
    {
        $gmailRow = [
            'receipt_mail_via' => 'gmail',
            'gmail_address' => 'ada@gmail.com',
            'gmail_app_password' => 'sealed',
        ];
        $gmail = $this->settings([$gmailRow, $gmailRow, $gmailRow]);
        self::assertSame('gmail', $gmail->via());
        self::assertSame('ada@gmail.com', $gmail->gmailAddress());
        self::assertTrue($gmail->hasGmailPassword());

        $serverRow = [
            'receipt_mail_via' => 'server',
            'gmail_address' => 4,
            'gmail_app_password' => '',
        ];
        $server = $this->settings([$serverRow, $serverRow, $serverRow]);
        self::assertSame('server', $server->via());
        self::assertSame('', $server->gmailAddress());
        self::assertFalse($server->hasGmailPassword());
        self::assertFalse($this->settings([['gmail_app_password' => 1]])->hasGmailPassword());
    }

    public function testAMissingMallRowIsRefused(): void
    {
        $settings = $this->settings([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the mall has no clock');
        $settings->via();
    }

    public function testGmailIsAbsentUntilTheAddressAndPasswordAreBothStored(): void
    {
        self::assertNull($this->settings([[
            'receipt_mail_via' => 'server',
            'gmail_address' => 'ada@gmail.com',
            'gmail_app_password' => SealedSecret::seal('abcdefghijklmnop'),
        ]])->gmail());

        self::assertNull($this->settings([
            ['receipt_mail_via' => 'gmail', 'gmail_address' => 'ada@gmail.com', 'gmail_app_password' => ''],
            ['receipt_mail_via' => 'gmail', 'gmail_address' => 'ada@gmail.com', 'gmail_app_password' => ''],
        ])->gmail());

        self::assertNull($this->settings([
            ['receipt_mail_via' => 'gmail', 'gmail_address' => '', 'gmail_app_password' => 'sealed'],
            ['receipt_mail_via' => 'gmail', 'gmail_address' => '', 'gmail_app_password' => 'sealed'],
            ['receipt_mail_via' => 'gmail', 'gmail_address' => '', 'gmail_app_password' => 'sealed'],
        ])->gmail());
    }

    public function testGmailOpensTheStoredPassword(): void
    {
        $sealed = SealedSecret::seal('abcdefghijklmnop');
        $row = [
            'receipt_mail_via' => 'gmail',
            'gmail_address' => 'ada@gmail.com',
            'gmail_app_password' => $sealed,
        ];
        $account = $this->settings([$row, $row, $row, $row])->gmail();

        self::assertSame([
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'username' => 'ada@gmail.com',
            'password' => 'abcdefghijklmnop',
            'from' => 'ada@gmail.com',
        ], $account);
    }

    public function testSavingGmailSealsANewPassword(): void
    {
        $script = $this->script([]);
        $settings = new ReceiptMailSettings($script);
        $settings->save('gmail', 'ada@gmail.com', 'abcdefghijklmnop');

        self::assertCount(1, $script->changes);
        self::assertStringContainsString('gmail_app_password = ?', $script->changes[0]['sql']);
        self::assertSame('gmail', $script->changes[0]['params'][0]);
        self::assertSame('ada@gmail.com', $script->changes[0]['params'][1]);
        $stored = $script->changes[0]['params'][2];
        self::assertIsString($stored);
        self::assertSame('abcdefghijklmnop', SealedSecret::open($stored));
    }

    public function testSavingWithoutAPasswordClearsTheStoredSecret(): void
    {
        $script = $this->script([[
            'receipt_mail_via' => 'server',
            'gmail_address' => '',
            'gmail_app_password' => '',
        ]]);
        $settings = new ReceiptMailSettings($script);
        $settings->save('server', '', '');

        self::assertCount(1, $script->changes);
        self::assertStringContainsString('gmail_app_password = NULL', $script->changes[0]['sql']);
        self::assertSame(['server', null], $script->changes[0]['params']);
    }

    public function testSavingKeepsThePasswordAlreadyOnTheMall(): void
    {
        $sealed = SealedSecret::seal('abcdefghijklmnop');
        $row = [
            'receipt_mail_via' => 'server',
            'gmail_address' => '',
            'gmail_app_password' => $sealed,
        ];
        $script = $this->script([$row, $row]);
        $settings = new ReceiptMailSettings($script);
        $settings->save('server', '', '');

        self::assertCount(1, $script->changes);
        $stored = $script->changes[0]['params'][2];
        self::assertIsString($stored);
        self::assertSame('abcdefghijklmnop', SealedSecret::open($stored));
    }

    public function testAStoredPasswordThatIsNotTextIsNotReused(): void
    {
        $script = $this->script([
            ['gmail_app_password' => 'present'],
            ['gmail_app_password' => 5],
        ]);
        $settings = new ReceiptMailSettings($script);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('a mail password is required');
        $settings->save('server', '', '');
    }

    public function testGmailSaveRefusesAMissingAddressOrPassword(): void
    {
        $missingAddress = new ReceiptMailSettings($this->script([]));
        try {
            $missingAddress->save('gmail', '', 'abcdefghijklmnop');
            self::fail('gmail was saved without an address');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Gmail needs an address and an app password', $error->getMessage());
        }

        $missingPassword = new ReceiptMailSettings($this->script([[
            'gmail_app_password' => '',
        ]]));
        try {
            $missingPassword->save('gmail', 'ada@gmail.com', '');
            self::fail('gmail was saved without a password');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Gmail needs an address and an app password', $error->getMessage());
        }
    }

    /**
     * @param list<array<string, mixed>|null> $rows
     */
    private function settings(array $rows): ReceiptMailSettings
    {
        return new ReceiptMailSettings($this->script($rows));
    }

    /**
     * @param list<array<string, mixed>|null> $rows
     */
    private function script(array $rows): MailRowScript
    {
        return new MailRowScript($this->createStub(PDO::class), $rows);
    }
}

final class MailRowScript extends Statements
{
    /** @var list<array{sql: string, params: list<mixed>}> */
    public array $changes = [];

    /** @var list<array<string, mixed>|null> */
    private array $queue;

    /**
     * @param list<array<string, mixed>|null> $queue
     */
    public function __construct(PDO $pdo, array $queue)
    {
        parent::__construct($pdo);
        $this->queue = $queue;
    }

    public function rows(string $sql): array
    {
        if ($this->queue === []) {
            return [];
        }
        $next = array_shift($this->queue);
        if (!is_array($next)) {
            return [];
        }

        return [$next];
    }

    /**
     * @param list<mixed> $params
     */
    public function change(string $sql, array $params): void
    {
        $this->changes[] = ['sql' => $sql, 'params' => $params];
    }
}
