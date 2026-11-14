<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Password;

use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Feature\Mall\ReceiptMailSettings;
use PerfectConsign\Feature\Password\LoginAccount;
use PerfectConsign\Feature\Password\LoginAccounts;
use PerfectConsign\Feature\Password\OwnPassword;
use PerfectConsign\Feature\Password\PasswordChoice;
use PerfectConsign\Feature\Password\PasswordRecovery;
use PerfectConsign\Feature\Password\RecoveryAddress;
use PerfectConsign\Feature\Password\ResetController;
use PerfectConsign\Feature\Password\ResetLetter;
use PerfectConsign\Feature\Password\ResetRequests;
use PerfectConsign\Feature\Password\ResetTokens;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Mail\GmailSender;
use PerfectConsign\Shared\Mail\LetterMailbox;
use PerfectConsign\Shared\Mail\OutboundMail;
use PerfectConsign\Shared\Mail\PlatformOperatorNotice;
use PerfectConsign\Shared\Tenant\TenantRecord;
use PerfectConsign\Tests\Feature\Coverage\CapturesPage;
use PerfectConsign\Tests\Feature\Coverage\MallDoor;
use PerfectConsign\Tests\Feature\Coverage\RoutedStatements;
use PerfectConsign\Tests\Support\ScriptPdo;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

final class PasswordMissLinesTest extends TestCase
{
    use CapturesPage;

    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testCompanyMailResetsTheRoleAfterTheMailboxIsRead(): void
    {
        $pdo = new ScriptPdo();
        $pdo->onQuery('FROM ' . MallDoor::SCHEMA . '.mall', [[
            'email' => 'ada@example.test',
            'receipt_mail_via' => 'server',
            'gmail_address' => '',
            'gmail_app_password' => '',
        ]]);
        $accounts = new LoginAccounts($pdo, new DatabaseRole($pdo));

        $mail = $accounts->companyMail($this->tenant());

        self::assertSame('ada@example.test', $mail['email']);
        self::assertNull($mail['gmail']);
    }

    public function testATakenOwnerEmailAndAMissingOwnerAreRefused(): void
    {
        $hash = password_hash('current-pass', PASSWORD_DEFAULT);
        $duplicate = new PDOException('duplicate');
        $duplicate->errorInfo = ['23505', null, 'duplicate'];
        $other = new PDOException('other');
        $other->errorInfo = ['23503', null, 'other'];
        $passwords = new OwnPassword(
            $this->passwordPdo($hash, $duplicate, 1),
            new DatabaseRole($this->rolePdo()),
            new PasswordChoice(),
            new RecoveryAddress(),
        );
        $missing = new OwnPassword(
            $this->passwordPdo($hash, null, 0),
            new DatabaseRole($this->rolePdo()),
            new PasswordChoice(),
            new RecoveryAddress(),
        );
        $foreign = new OwnPassword(
            $this->passwordPdo($hash, $other, 1),
            new DatabaseRole($this->rolePdo()),
            new PasswordChoice(),
            new RecoveryAddress(),
        );

        $this->assertInvalid('That email is already used on another sign-in.', function () use ($passwords): void {
            $passwords->emailOwner($this->tenant(), MallDoor::ACCOUNT, 'current-pass', 'ada@example.test');
        });
        $this->assertInvalid('That sign-in was not found.', function () use ($missing): void {
            $missing->emailStaff($this->tenant(), MallDoor::ACCOUNT, 'current-pass', 'ada@example.test');
        });
        try {
            $foreign->emailStaff($this->tenant(), MallDoor::ACCOUNT, 'current-pass', 'ada@example.test');
            self::fail('a foreign database error must surface');
        } catch (PDOException $error) {
            self::assertSame('23503', $error->errorInfo[0] ?? null);
        }
    }

    public function testABadResetPasswordIsShownOnTheForm(): void
    {
        $door = MallDoor::open($this, new Request('POST', '/' . MallDoor::SLUG . '/reset/' . self::TOKEN, [], [
            'csrf' => 'csrf-token',
            'password' => 'short',
            'confirm' => 'short',
        ]));
        $html = $this->capture(function () use ($door): void {
            $this->resets($door)->submit(MallDoor::SLUG, self::TOKEN);
        });

        self::assertNotSame('', $html);
    }

    public function testResetMailReportsARefusedDelivery(): void
    {
        $pdo = new ScriptPdo();
        $logger = $this->createStub(LoggerInterface::class);
        $mailbox = new ReadyMailbox($logger, new RoutedStatements($pdo));
        $mailbox->fail = true;
        $letter = new ResetLetter(
            $pdo,
            new DatabaseRole($pdo),
            new ResetTokens($pdo),
            $mailbox,
            $logger,
            new PlatformOperatorNotice($logger),
        );
        $account = new LoginAccount('owner', MallDoor::ACCOUNT, 'ada', 'ada@example.test');

        self::assertTrue($letter->sendReset($this->tenant(), $account, 'https://example.test', new LoginAccounts($pdo, new DatabaseRole($pdo))));
    }

    public function testAResetAllowanceRollsBackWhenTheLockFails(): void
    {
        $pdo = new ScriptPdo();
        $pdo->oncePrepareThrows('pg_advisory_xact_lock');
        $requests = new ResetRequests($pdo, new Request('POST', '/reset', [], [], '', '', '203.0.113.4'));

        $this->expectException(\RuntimeException::class);
        $requests->allow($this->tenant(), 'ada');
    }

    public function testIssuingATokenRollsBackAndAUsedTokenIsExpired(): void
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willThrowException(new \RuntimeException('insert failed'));
        $pdo->method('prepare')->willReturn($statement);
        $tokens = new ResetTokens($pdo);

        try {
            $tokens->issue($this->tenant(), new LoginAccount('owner', MallDoor::ACCOUNT, 'ada', 'ada@example.test'));
            self::fail('the token insert must fail');
        } catch (\RuntimeException $error) {
            self::assertSame('insert failed', $error->getMessage());
        }

        $spent = $this->createStub(PDO::class);
        $spentStatement = $this->createStub(PDOStatement::class);
        $spentStatement->method('execute')->willReturn(true);
        $spentStatement->method('rowCount')->willReturn(0);
        $spent->method('prepare')->willReturn($spentStatement);
        $this->expectException(\InvalidArgumentException::class);
        (new ResetTokens($spent))->consume(MallDoor::ACCOUNT);
    }

    public function testACheckChunkAboveThreeDigitsIsRefused(): void
    {
        $chunk = new ReflectionMethod(\PerfectConsign\Feature\Payout\CheckWords::class, 'chunk');
        $this->expectException(\InvalidArgumentException::class);
        $chunk->invoke(new \PerfectConsign\Feature\Payout\CheckWords(), 1000);
    }

    private function resets(MallDoor $door): ResetController
    {
        $letters = new ResetLetter(
            $door->pdo,
            $door->roles,
            new ResetTokens($door->pdo),
            new ReadyMailbox($this->createStub(LoggerInterface::class), new RoutedStatements($door->pdo)),
            $this->createStub(LoggerInterface::class),
            new PlatformOperatorNotice($this->createStub(LoggerInterface::class)),
        );

        return new ResetController($door->app, new PasswordRecovery(
            $door->pdo,
            $door->roles,
            new LoginAccounts($door->pdo, $door->roles),
            new ResetTokens($door->pdo),
            new ResetRequests($door->pdo, $door->app->request()),
            new PasswordChoice(),
            $letters,
        ));
    }

    private function tenant(): TenantRecord
    {
        return new TenantRecord(
            MallDoor::TENANT,
            MallDoor::SLUG,
            MallDoor::SCHEMA,
            'poster_abababababababababababababababab',
            'Gap',
        );
    }

    private function rolePdo(): PDO
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willReturn(0);

        return $pdo;
    }

    private function passwordPdo(string $hash, ?PDOException $updateError, int $updated): PDO
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willReturn(0);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($hash, $updateError, $updated): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            if (str_contains($sql, 'SET email')) {
                if ($updateError !== null) {
                    $statement->method('execute')->willThrowException($updateError);
                } else {
                    $statement->method('execute')->willReturn(true);
                }
                $statement->method('rowCount')->willReturn($updated);

                return $statement;
            }
            $statement->method('execute')->willReturn(true);
            $statement->method('fetchColumn')->willReturn($hash);

            return $statement;
        });

        return $pdo;
    }

    private function assertInvalid(string $message, callable $run): void
    {
        try {
            $run();
            self::fail($message);
        } catch (\InvalidArgumentException $error) {
            self::assertSame($message, $error->getMessage());
        }
    }
}

final class ReadyMailbox extends LetterMailbox
{
    public bool $fail = false;

    public bool $open = true;

    public function __construct(LoggerInterface $logger, RoutedStatements $statements)
    {
        parent::__construct(
            new ReceiptMailSettings($statements),
            new OutboundMail($logger),
            new GmailSender(new PlatformOperatorNotice($logger)),
        );
    }

    public function ready(): bool
    {
        return $this->open;
    }

    public function send(string $to, string $subject, string $body, string $purpose = 'vendor letter mail was refused'): void
    {
        unset($to, $subject, $body, $purpose);
        if ($this->fail) {
            throw new \RuntimeException('refused');
        }
    }
}
