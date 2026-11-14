<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Coverage;

use ArrayObject;
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
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Auth\OwnerAccess;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\View;
use PDO;
use PDOStatement;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;
use Throwable;

final class DoorKnobs
{
    public Throwable|null $executeError = null;

    public string $executeThrowSql = '';

    public int|null $updateCount = null;
}

final class MallDoor
{
    public const SLUG = 'gapmall';

    public const TENANT = '01900000-0000-7000-8000-0000000000bb';

    public const ACCOUNT = '01900000-0000-7000-8000-0000000000aa';

    public const SCHEMA = 'tenant_abababababababababababababababab';

    public const DRAWER = '01900000-0000-7000-8000-0000000000e1';

    public const JOURNAL = '01900000-0000-7000-8000-0000000000f1';

    public const RUN = '01900000-0000-7000-8000-0000000000d1';

    public const VENDOR = '01900000-0000-7000-8000-0000000000c1';

    public const PARTY = '01900000-0000-7000-8000-0000000000a1';

    public const PERSON = '01900000-0000-7000-8000-0000000000c2';

    /** @param ArrayObject<string, string> $bag */
    public function __construct(
        public readonly MallApp $app,
        public readonly MallSettings $mall,
        public readonly ScriptedStatements $statements,
        public readonly PDO $pdo,
        public readonly Session $session,
        public readonly Actor $actor,
        public readonly ActiveRegister $register,
        public readonly LedgerStore $ledger,
        public readonly DatabaseRole $roles,
        public readonly DoorKnobs $knobs,
        public readonly ArrayObject $bag,
    ) {
    }

    public static function open(TestCase $test, Request $request): self
    {
        $knobs = new DoorKnobs();
        /** @var ArrayObject<string, string> $bag */
        $bag = new ArrayObject([
            'csrf' => 'csrf-token',
            'owner_slug' => self::SLUG,
            'actor_role' => 'owner',
            'actor_account' => self::ACCOUNT,
            'credentials_version' => '1',
            'owner_name' => 'Ada',
            'signed_in_at' => (string) time(),
            'last_seen_at' => (string) time(),
        ]);
        /** @var Session&Stub $session */
        $session = self::stub($test, Session::class);
        $session->method('get')->willReturnCallback(static function (string $key) use ($bag): mixed {
            return $bag[$key] ?? null;
        });
        $session->method('set')->willReturnCallback(static function (string $key, mixed $value) use ($bag): void {
            $bag[$key] = is_string($value) ? $value : '';
        });
        $session->method('has')->willReturnCallback(static function (string $key) use ($bag): bool {
            return $bag->offsetExists($key);
        });
        $session->method('delete')->willReturnCallback(static function (string $key) use ($bag): void {
            $bag->offsetUnset($key);
        });
        $session->method('destroy')->willReturnCallback(static function () use ($bag): void {
            $bag->exchangeArray([]);
        });
        /** @var PDO&Stub $pdo */
        $pdo = self::stub($test, PDO::class);
        $pdo->method('exec')->willReturn(0);
        $pdo->method('prepare')->willReturnCallback(static function (string $sql) use ($test, $knobs): PDOStatement {
            /** @var PDOStatement&Stub $statement */
            $statement = self::stub($test, PDOStatement::class);
            $statement->method('execute')->willReturnCallback(static function () use ($sql, $knobs): bool {
                if ($knobs->executeError !== null && $knobs->executeThrowSql !== '' && str_contains($sql, $knobs->executeThrowSql)) {
                    throw $knobs->executeError;
                }

                return true;
            });
            $statement->method('fetchAll')->willReturnCallback(static function () use ($sql): array {
                if (str_contains($sql, 'register_sessions')) {
                    return [['id' => self::DRAWER, 'name' => 'Front']];
                }

                return [];
            });
            $statement->method('fetch')->willReturnCallback(static function () use ($sql): array|false {
                if (str_contains($sql, 'schema_name')) {
                    return [
                        'id' => self::TENANT,
                        'slug' => self::SLUG,
                        'schema_name' => self::SCHEMA,
                        'display_name' => 'Gap',
                    ];
                }

                return false;
            });
            $statement->method('fetchColumn')->willReturnCallback(static function () use ($sql): int|string {
                if (str_contains($sql, 'credentials_version')) {
                    return '1';
                }
                if (str_contains($sql, 'count(*)')) {
                    return 1;
                }

                return 0;
            });
            $statement->method('rowCount')->willReturnCallback(static function () use ($sql, $knobs): int {
                if ($knobs->updateCount !== null && str_contains($sql, 'UPDATE platform.owners')) {
                    return $knobs->updateCount;
                }

                return 1;
            });

            return $statement;
        });
        /** @var PDO&Stub $statementsPdo */
        $statementsPdo = self::stub($test, PDO::class);
        $statements = new ScriptedStatements($statementsPdo);
        /** @var LedgerStore&Stub $ledger */
        $ledger = self::stub($test, LedgerStore::class);
        $ledger->method('roleAccount')->willReturn(self::ACCOUNT);
        $ledger->method('post')->willReturn(self::JOURNAL);
        $ledger->method('periods')->willReturn([]);
        /** @var LoggerInterface&Stub $logger */
        $logger = self::stub($test, LoggerInterface::class);
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
        $roles = new DatabaseRole($pdo);
        $mall = new MallSettings(
            $statements,
            new CompanyStore($statements, new DatabaseRole($pdo), $pdo, new CompanyLines()),
            new MallDay($statements),
        );
        $register = new ActiveRegister($pdo, $session, $mall, new MallClock($mall));
        $app = new MallApp(
            $pdo,
            $session,
            $view,
            $request,
            new OwnerAccess(),
            $mall,
            $register,
            $tenants,
            new FrontDoor(
                $missing,
                new PriorClose($session, $request),
                $owner,
                new PostedRefusal($logger),
                new Actor($session),
                new CredentialStamp($pdo, $session, new DatabaseRole($pdo)),
            ),
        );

        return new self($app, $mall, $statements, $pdo, $session, new Actor($session), $register, $ledger, $roles, $knobs, $bag);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T&Stub
     */
    private static function stub(TestCase $test, string $class): Stub
    {
        $stub = new ReflectionMethod(TestCase::class, 'createStub')->invoke($test, $class);
        if (!$stub instanceof Stub || !is_a($stub, $class)) {
            throw new RuntimeException('the test stub could not be built');
        }

        return $stub;
    }
}
