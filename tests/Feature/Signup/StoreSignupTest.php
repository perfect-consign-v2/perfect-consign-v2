<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Signup;

use PDO;
use PDOException;
use PDOStatement;
use PerfectConsign\Feature\Signup\SignupAdmin;
use PerfectConsign\Feature\Signup\SignupInput;
use PerfectConsign\Feature\Signup\SignupMail;
use PerfectConsign\Feature\Signup\SignupSlug;
use PerfectConsign\Feature\Signup\SignupToken;
use PerfectConsign\Feature\Signup\StoreSignup;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Mail\GmailSender;
use PerfectConsign\Shared\Mail\OutboundMail;
use PerfectConsign\Shared\Mail\PlatformMailbox;
use PerfectConsign\Shared\Mail\PlatformOperatorNotice;
use PerfectConsign\Tests\Support\QuietAudit;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionProperty;
use RuntimeException;

/**
 * The parts of signup that do not need the database: the input rules, the
 * link token, the store address, the letters, and how a refusal from a signup
 * function becomes a sentence. The flow itself is proved against the database
 * in StoreSignupFlowTest and sql/prove_store_signup.sql.
 */
final class StoreSignupTest extends TestCase
{
    private const ADMIN = ['id' => '01900000-0000-7000-8000-0000000000aa', 'email' => 'admin@example.test'];

    public function testTheInputRulesSayWhatIsWrong(): void
    {
        $cases = [
            ['', 'owner@harbor.test', 'password1', 'password1', 'Enter a store name.'],
            [str_repeat('H', 121), 'owner@harbor.test', 'password1', 'password1', 'Enter a store name.'],
            ['Harbor', 'not-an-email', 'password1', 'password1', 'Enter a valid email address.'],
            ['Harbor', str_repeat('a', 190) . '@harbor.test', 'password1', 'password1', 'Enter a valid email address.'],
            ['Harbor', 'owner@harbor.test', 'short', 'short', 'Password must be at least 8 characters.'],
            ['Harbor', 'owner@harbor.test', 'password1', 'password2', 'Password confirmation does not match.'],
        ];
        foreach ($cases as [$name, $email, $password, $confirm, $message]) {
            try {
                SignupInput::checked($name, $email, $password, $confirm);
                self::fail($message);
            } catch (RuntimeException $error) {
                self::assertSame($message, $error->getMessage());
            }
        }
        self::assertSame(['Harbor', 'owner@harbor.test'], SignupInput::checked(' Harbor ', ' Owner@Harbor.TEST ', 'password1', 'password1'));
    }

    public function testALinkTokenIsRandomAndOnlyItsHashIsKept(): void
    {
        $first = SignupToken::issue();
        $second = SignupToken::issue();
        self::assertNotSame($first['token'], $second['token']);
        self::assertTrue(SignupToken::shaped($first['token']));
        self::assertSame(hash('sha256', $first['token']), $first['hash']);
        self::assertSame($first['hash'], SignupToken::hash($first['token']));
        self::assertFalse(SignupToken::shaped('short'));
        self::assertFalse(SignupToken::shaped(str_repeat('a', 42) . '/'));
    }

    public function testAStoreNameBecomesAnAddressAndAnEmailAUsername(): void
    {
        self::assertSame('harbor-antique', SignupSlug::fromName(' Harbor Antique '));
        self::assertSame('s123-shop', SignupSlug::fromName('123 shop'));
        self::assertSame('s', SignupSlug::fromName('---'));
        self::assertSame(str_repeat('a', 63), SignupSlug::fromName(str_repeat('a', 80)));
        self::assertSame(str_repeat('a', 62), SignupSlug::fromName(str_repeat('a', 62) . '-' . str_repeat('b', 20)));
        self::assertTrue(SignupSlug::reserved('admin'));
        self::assertTrue(SignupSlug::reserved('signup'));
        self::assertFalse(SignupSlug::reserved('harbor'));
        self::assertSame('ada-lovelace', SignupSlug::username('Ada.Lovelace@example.test'));
        self::assertSame('u7', SignupSlug::username('7@example.test'));
        self::assertSame(str_repeat('a', 63), SignupSlug::username(str_repeat('a', 70) . '@example.test'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Enter a valid email address.');
        SignupSlug::username('@example.test');
    }

    public function testALetterThatCannotBeSentIsLoggedAndReported(): void
    {
        $folder = dirname(__DIR__, 3) . '/var';
        $previous = getenv('STALL_MAIL_FILE');
        putenv('STALL_MAIL_FILE=' . $folder);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))->method('error');
        try {
            $mail = $this->mail($logger);
            self::assertTrue($mail->ready());
            self::assertFalse($mail->link('owner@harbor.test', 'https://example.test', str_repeat('a', 43), 24));
            self::assertFalse($mail->known('owner@harbor.test', 'https://example.test'));
        } finally {
            if (is_string($previous) && $previous !== '') {
                putenv('STALL_MAIL_FILE=' . $previous);
            } else {
                putenv('STALL_MAIL_FILE');
            }
        }
    }

    public function testTheSignupReadsRefuseWhatTheDatabaseDidNotGive(): void
    {
        $empty = $this->database(fetch: false, column: false, query: false);
        $signup = new StoreSignup($empty, $this->mail(), $this->audit($empty), new NullLogger());
        $this->refused('the signup challenge was not issued', static fn () => $signup->challenge('203.0.113.1'));
        $this->refused('signup rules are missing', static fn () => $signup->pendingHours());
        self::assertNull($signup->state(str_repeat('a', 43)));
        self::assertNull($signup->verify(str_repeat('a', 43)));

        $noSlug = $this->database(fetch: ['status' => 'pending', 'email' => 'owner@harbor.test', 'slug' => null], column: 'expired');
        $reading = new StoreSignup($noSlug, $this->mail(), $this->audit($noSlug), new NullLogger());
        self::assertSame(['status' => 'pending', 'email' => 'owner@harbor.test', 'slug' => ''], $reading->state(str_repeat('a', 43)));
        self::assertSame('expired', $reading->verify(str_repeat('a', 43)));
    }

    public function testAnOperatorIsToldTheSentenceTheDatabaseRaised(): void
    {
        $taken = $this->database(throws: $this->refusal('23505', 'That email already signs in to a store.'));
        $operator = new SignupAdmin($taken, $this->mail(), $this->audit($taken));
        $this->refused('That email already signs in to a store.', fn () => $operator->create('Harbor', 'owner@harbor.test', 'password1', 'password1', self::ADMIN));

        $odd = $this->database(throws: $this->refusal('42501', 'permission denied for function signup_admin_create'));
        $this->refused('That store could not be queued.', fn () => (new SignupAdmin($odd, $this->mail(), $this->audit($odd)))
            ->create('Harbor', 'owner@harbor.test', 'password1', 'password1', self::ADMIN));

        $lower = $this->database(throws: $this->refusal('23514', 'new row violates check constraint "store_signups_email_chk"'));
        $this->refused('That signup was not found.', fn () => (new SignupAdmin($lower, $this->mail(), $this->audit($lower)))
            ->discard('01900000-0000-7000-8000-0000000000cc', self::ADMIN));
        $this->refused('That signup was not found.', fn () => (new SignupAdmin($lower, $this->mail(), $this->audit($lower)))
            ->resend('01900000-0000-7000-8000-0000000000cc', 'https://example.test', self::ADMIN, 24));

        $queued = $this->database(column: '01900000-0000-7000-8000-0000000000cc');
        self::assertSame('owner@harbor.test', (new SignupAdmin($queued, $this->mail(), $this->audit($queued)))
            ->create('Harbor', ' Owner@Harbor.test', 'password1', 'password1', self::ADMIN));
    }

    public function testAnOperatorActionOnAMissingSignupIsRefused(): void
    {
        $missing = $this->database(column: false, query: false, rows: ['not-a-row']);
        $operator = new SignupAdmin($missing, $this->mail(), $this->audit($missing));
        $this->refused('That signup was not found.', fn () => $operator->resend('01900000-0000-7000-8000-0000000000cc', 'https://example.test', self::ADMIN, 24));
        $this->refused('That signup was not found.', fn () => $operator->discard('01900000-0000-7000-8000-0000000000cc', self::ADMIN));
        $this->refused('signup counts could not be read', static fn () => $operator->reapedLately());
        $this->refused('unconfirmed stores could not be read', static fn () => $operator->unconfirmedStores());
        self::assertSame([], $operator->listed());

        $previous = getenv('STALL_MAIL_FILE');
        putenv('STALL_MAIL_FILE=' . dirname(__DIR__, 3) . '/var');
        try {
            $found = $this->database(column: 'owner@harbor.test');
            $this->refused('The new link could not be emailed. Try again.', fn () => (new SignupAdmin($found, $this->mail(), $this->audit($found)))
                ->resend('01900000-0000-7000-8000-0000000000cc', 'https://example.test', self::ADMIN, 24));
        } finally {
            if (is_string($previous) && $previous !== '') {
                putenv('STALL_MAIL_FILE=' . $previous);
            } else {
                putenv('STALL_MAIL_FILE');
            }
        }
    }

    /**
     * @param array<string, mixed>|false $fetch
     * @param list<mixed> $rows
     */
    private function database(
        array|false $fetch = false,
        mixed $column = 'owner@harbor.test',
        bool $query = true,
        ?PDOException $throws = null,
        array $rows = [],
    ): PDO {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($fetch, $column, $throws, $rows): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            if ($throws !== null && str_contains($sql, 'platform.signup_')) {
                $statement->method('execute')->willThrowException($throws);
            } else {
                $statement->method('execute')->willReturn(true);
            }
            $statement->method('fetch')->willReturn($fetch);
            $statement->method('fetchColumn')->willReturn($column);
            $statement->method('fetchAll')->willReturn($rows);
            $statement->method('rowCount')->willReturn(1);

            return $statement;
        });
        $pdo->method('query')->willReturnCallback(fn (): PDOStatement|false => $query ? $this->createStub(PDOStatement::class) : false);

        return $pdo;
    }

    private function refusal(string $state, string $sentence): PDOException
    {
        $error = new PDOException('SQLSTATE[' . $state . ']: ERROR:  ' . $sentence);
        $error->errorInfo = [$state, 7, 'ERROR:  ' . $sentence];
        (new ReflectionProperty(\Exception::class, 'code'))->setValue($error, $state);

        return $error;
    }

    private function mail(?LoggerInterface $logger = null): SignupMail
    {
        $quiet = new NullLogger();
        $notices = new PlatformOperatorNotice($quiet);

        return new SignupMail(new PlatformMailbox(new OutboundMail($quiet), new GmailSender($notices), $notices), $logger ?? $quiet);
    }

    private function audit(PDO $pdo): \PerfectConsign\Feature\Admin\AdminAudit
    {
        return QuietAudit::admin($pdo, new Request('POST', '/signup', [], [], '', 'https://example.test', '203.0.113.1'));
    }

    private function refused(string $message, callable $work): void
    {
        try {
            $work();
            self::fail('expected: ' . $message);
        } catch (RuntimeException $error) {
            self::assertSame($message, $error->getMessage());
        }
    }
}
