<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Signup;

use ArrayObject;
use PDO;
use PDOStatement;
use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\ActiveRegister;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\CredentialStamp;
use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Bootstrap\FrontDoor;
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
use PerfectConsign\Feature\Signup\PlatformSettings;
use PerfectConsign\Feature\Signup\SignupController;
use PerfectConsign\Feature\Signup\SignupMail;
use PerfectConsign\Feature\Signup\StoreSignup;
use PerfectConsign\Shared\Auth\OwnerAccess;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\ResponseSent;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Mail\GmailSender;
use PerfectConsign\Shared\Mail\OutboundMail;
use PerfectConsign\Shared\Mail\PlatformMailbox;
use PerfectConsign\Shared\Mail\PlatformOperatorNotice;
use PerfectConsign\Tests\Support\QuietAudit;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SignupControllerTest extends TestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const REFUSED = 'That link is invalid or expired. Start again at Create your store.';

    private string $mailFile;

    private mixed $previousMail;

    protected function setUp(): void
    {
        $this->mailFile = dirname(__DIR__, 3) . '/var/test-signup-controller-mail.txt';
        $this->previousMail = getenv('STALL_MAIL_FILE');
        putenv('STALL_MAIL_FILE=' . $this->mailFile);
    }

    protected function tearDown(): void
    {
        if (is_file($this->mailFile)) {
            unlink($this->mailFile);
        }
        if (is_string($this->previousMail) && $this->previousMail !== '') {
            putenv('STALL_MAIL_FILE=' . $this->previousMail);
        } else {
            putenv('STALL_MAIL_FILE');
        }
    }

    public function testTheFormFollowsTheSwitchAndCarriesTheBrowserCheck(): void
    {
        [$off] = $this->controller($this->request('GET', '/signup', []), ['enabled' => false]);
        $closed = $this->capture(static fn () => $off->show());
        self::assertStringContainsString('Self-serve signup is turned off', $closed);
        self::assertStringNotContainsString('name="store_name"', $closed);

        [$on] = $this->controller($this->request('GET', '/signup', []), []);
        $form = $this->capture(static fn () => $on->show());
        self::assertStringContainsString('name="store_name"', $form);
        self::assertStringContainsString('data-proof-seed="' . str_repeat('c', 32) . '"', $form);
        self::assertStringContainsString('data-proof-bits="18"', $form);
    }

    public function testASentSignupShowsTheSameSentenceAndMailsTheLink(): void
    {
        [$controller] = $this->controller($this->signupPost(), ['outcome' => 'pending']);
        $page = $this->capture(static fn () => $controller->create());
        self::assertStringContainsString(StoreSignup::SENT, $page);
        $mail = (string) file_get_contents($this->mailFile);
        self::assertStringContainsString('To: owner@harbor.test', $mail);
        self::assertMatchesRegularExpression('#https://example\.test/signup/verify/[A-Za-z0-9_-]{43}\n#', $mail);
        self::assertStringContainsString('The link works for 24 hours.', $mail);

        [$known] = $this->controller($this->signupPost(), ['outcome' => 'known']);
        self::assertStringContainsString(StoreSignup::SENT, $this->capture(static fn () => $known->create()));
        self::assertStringContainsString('You already have a store', (string) file_get_contents($this->mailFile));

        [$quiet] = $this->controller($this->signupPost(), ['outcome' => 'quiet']);
        self::assertStringContainsString(StoreSignup::SENT, $this->capture(static fn () => $quiet->create()));
    }

    public function testARefusedSignupKeepsTheFieldsAndSaysWhy(): void
    {
        $cases = [
            'challenge' => 'Your browser did not finish its check. Try again.',
            'limited' => 'Too many signup attempts. Try again later.',
            'busy' => 'Signups are busy right now. Try again in an hour.',
            'odd' => 'That store could not be created.',
        ];
        foreach ($cases as $outcome => $sentence) {
            [$controller] = $this->controller($this->signupPost(), ['outcome' => $outcome]);
            $page = $this->capture(static fn () => $controller->create());
            self::assertStringContainsString($sentence, $page);
            self::assertStringContainsString('value="Harbor"', $page);
            self::assertStringContainsString('value="owner@harbor.test"', $page);
        }
        [$short] = $this->controller($this->signupPost(['password' => 'short', 'password_confirm' => 'short']), []);
        self::assertStringContainsString('Password must be at least 8 characters.', $this->capture(static fn () => $short->create()));

        [$closed] = $this->controller($this->signupPost(), ['outcome' => 'closed']);
        self::assertStringContainsString('Self-serve signup is turned off', $this->capture(static fn () => $closed->create()));

        $saved = [];
        foreach (['STALL_MAIL_FILE', 'STALL_SMTP_USERNAME', 'STALL_GMAIL_ADDRESS'] as $name) {
            $saved[$name] = getenv($name);
            putenv($name);
        }
        try {
            [$mute] = $this->controller($this->signupPost(), ['outcome' => 'pending']);
            self::assertStringContainsString(
                'Store signup cannot send email right now. Try again later.',
                $this->capture(static fn () => $mute->create())
            );
        } finally {
            foreach ($saved as $name => $value) {
                if (is_string($value) && $value !== '') {
                    putenv($name . '=' . $value);
                }
            }
        }
    }

    public function testAPostWithoutTheSessionTokenExpiresTheForm(): void
    {
        [$controller, $bag] = $this->controller($this->signupPost(), []);
        $bag->offsetUnset('csrf');
        $html = '';
        ob_start();
        try {
            $controller->create();
            self::fail('an unsigned signup was accepted');
        } catch (ResponseSent $sent) {
            self::assertSame('csrf', $sent->getMessage());
        } finally {
            $html = (string) ob_get_clean();
        }
        self::assertStringContainsString('The form expired', $html);
    }

    public function testTheLinkPageFollowsWhereTheSignupStands(): void
    {
        [$pending] = $this->controller($this->request('GET', '/signup/verify/' . self::TOKEN, []), ['state' => 'pending']);
        $page = $this->capture(static fn () => $pending->verify(self::TOKEN));
        self::assertStringContainsString('Set up my store', $page);
        self::assertStringContainsString('owner@harbor.test', $page);
        self::assertStringNotContainsString('http-equiv="refresh"', $page);

        [$building] = $this->controller($this->request('GET', '/signup/verify/' . self::TOKEN, []), ['state' => 'provisioning']);
        $wait = $this->capture(static fn () => $building->verify(self::TOKEN));
        self::assertStringContainsString('<meta http-equiv="refresh" content="5">', $wait);
        self::assertStringContainsString('checks again on its own', $wait);

        [$failed] = $this->controller($this->request('GET', '/signup/verify/' . self::TOKEN, []), ['state' => 'failed']);
        self::assertStringContainsString('taking longer than it should', $this->capture(static fn () => $failed->verify(self::TOKEN)));

        [$ready, $readyBag] = $this->controller($this->request('GET', '/signup/verify/' . self::TOKEN, []), ['state' => 'ready']);
        $this->redirected(static fn () => $ready->verify(self::TOKEN));
        self::assertSame('Your store is ready. Sign in with your email and the password you chose.', $readyBag['notice'] ?? null);

        foreach (['expired', 'discarded', null] as $state) {
            [$gone, $goneBag] = $this->controller($this->request('GET', '/signup/verify/' . self::TOKEN, []), ['state' => $state]);
            $this->redirected(static fn () => $gone->verify(self::TOKEN));
            self::assertSame(self::REFUSED, $goneBag['warning'] ?? null);
        }
    }

    public function testPressingTheButtonConfirmsOnceAndComesBackToTheLink(): void
    {
        [$confirmed, $bag] = $this->controller($this->linkPost(), ['state' => 'pending', 'verify' => 'verified']);
        $this->redirected(static fn () => $confirmed->confirm(self::TOKEN));
        self::assertArrayNotHasKey('warning', $bag->getArrayCopy());

        foreach (['expired', 'discarded', false] as $status) {
            [$refused, $refusedBag] = $this->controller($this->linkPost(), ['state' => 'pending', 'verify' => $status]);
            $this->redirected(static fn () => $refused->confirm(self::TOKEN));
            self::assertSame(self::REFUSED, $refusedBag['warning'] ?? null);
        }
    }

    /**
     * @param array{enabled?: bool, outcome?: string, state?: string|null, verify?: string|false} $db
     * @return array{0: SignupController, 1: ArrayObject<string, string>}
     */
    private function controller(Request $request, array $db): array
    {
        $bag = new ArrayObject(['csrf' => 'csrf-token']);
        $session = $this->createStub(Session::class);
        $session->method('get')->willReturnCallback(static fn (string $key): mixed => $bag->offsetExists($key) ? $bag->offsetGet($key) : null);
        $session->method('set')->willReturnCallback(static function (string $key, mixed $value) use ($bag): void {
            $bag->offsetSet($key, is_string($value) ? $value : '');
        });
        $session->method('has')->willReturnCallback(static fn (string $key): bool => $bag->offsetExists($key));
        $session->method('delete')->willReturnCallback(static function (string $key) use ($bag): void {
            $bag->offsetUnset($key);
        });
        $pdo = $this->signupDatabase($db);
        $logger = $this->createStub(LoggerInterface::class);
        $view = new View(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'src');
        $tenants = new TenantLookup($pdo);
        $missing = new MissingPage($session, $logger, $view, $request, $tenants);
        $sessions = new LoginSession($session);
        $door = new FrontDoor(
            $missing,
            new PriorClose($session, $request),
            new OwnerGate(
                $session,
                $logger,
                $request,
                $tenants,
                $missing,
                new \PerfectConsign\Bootstrap\StoreAccounts($pdo, new StaffSignIn($pdo, $logger, $sessions), $sessions, $logger),
                \PerfectConsign\Tests\Support\SignInTestKit::gate($pdo),
            ),
            new PostedRefusal($logger),
            new Actor($session),
            new CredentialStamp($pdo, $session, new DatabaseRole($pdo)),
        );
        $rows = $this->createStub(Statements::class);
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
        $notices = new PlatformOperatorNotice($logger);
        $mail = new SignupMail(new PlatformMailbox(new OutboundMail($logger), new GmailSender($notices), $notices), $logger);

        return [
            new SignupController(
                $app,
                new PlatformSettings($pdo, $logger),
                new StoreSignup($pdo, $mail, QuietAudit::admin($pdo, $request), $logger),
            ),
            $bag,
        ];
    }

    /**
     * A stand-in for the platform signup functions, answering by statement.
     *
     * @param array{enabled?: bool, outcome?: string, state?: string|null, verify?: string|false} $db
     */
    private function signupDatabase(array $db): PDO
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($db): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('execute')->willReturn(true);
            $statement->method('rowCount')->willReturn(1);
            $state = $db['state'] ?? null;
            $statement->method('fetch')->willReturn(match (true) {
                str_contains($sql, 'signup_challenge') => ['seed' => str_repeat('c', 32), 'bits' => 18],
                str_contains($sql, 'signup_state') && is_string($state) => ['status' => $state, 'email' => 'owner@harbor.test', 'slug' => 'harbor'],
                default => false,
            });
            $statement->method('fetchColumn')->willReturn(match (true) {
                str_contains($sql, 'signup_request') => $db['outcome'] ?? 'pending',
                str_contains($sql, 'signup_verify') => $db['verify'] ?? 'verified',
                str_contains($sql, 'platform.settings') => ($db['enabled'] ?? true) ? 'true' : 'false',
                default => false,
            });

            return $statement;
        });
        $pdo->method('query')->willReturnCallback(function (): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('fetchColumn')->willReturn(24);

            return $statement;
        });

        return $pdo;
    }

    /**
     * @param array<string, string> $changes
     */
    private function signupPost(array $changes = []): Request
    {
        return $this->request('POST', '/signup', $changes + [
            'csrf' => 'csrf-token',
            'store_name' => 'Harbor',
            'email' => 'owner@harbor.test',
            'password' => 'password1',
            'password_confirm' => 'password1',
            'proof_seed' => str_repeat('c', 32),
            'proof_nonce' => '7',
        ]);
    }

    private function linkPost(): Request
    {
        return $this->request('POST', '/signup/verify/' . self::TOKEN, ['csrf' => 'csrf-token']);
    }

    /**
     * @param array<string, mixed> $post
     */
    private function request(string $method, string $path, array $post): Request
    {
        return new Request($method, $path, [], $post, '', 'https://example.test', '203.0.113.10');
    }

    private function redirected(callable $work): void
    {
        ob_start();
        try {
            $work();
            self::fail('expected a redirect');
        } catch (ResponseSent $sent) {
            self::assertSame('redirect', $sent->getMessage());
        } finally {
            ob_end_clean();
        }
    }

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
}
