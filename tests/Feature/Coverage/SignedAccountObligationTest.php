<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Coverage;

use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Bootstrap\JournalAudit;
use PerfectConsign\Bootstrap\LoginSession;
use PerfectConsign\Bootstrap\SaleCeiling;
use PerfectConsign\Bootstrap\SaleCeilingFlag;
use PerfectConsign\Feature\Activity\ActivityController;
use PerfectConsign\Feature\Activity\ActivityHref;
use PerfectConsign\Feature\Activity\ActivityLog;
use PerfectConsign\Feature\Activity\ActivityPeople;
use PerfectConsign\Feature\Admin\AdminApp;
use PerfectConsign\Feature\Admin\AdminEnterAs;
use PerfectConsign\Feature\Admin\AdminMfaController;
use PerfectConsign\Feature\Admin\AdminPath;
use PerfectConsign\Feature\Admin\AdminRouter;
use PerfectConsign\Feature\Admin\AdminSession;
use PerfectConsign\Feature\Admin\AdminSignInResult;
use PerfectConsign\Feature\Admin\Mfa\AdminMfaAttempt;
use PerfectConsign\Feature\Admin\Mfa\AdminMfaChallenge;
use PerfectConsign\Feature\Admin\Mfa\AdminMfaEnrollment;
use PerfectConsign\Feature\Admin\Mfa\AdminMfaFailures;
use PerfectConsign\Feature\Admin\Mfa\AdminMfaLogin;
use PerfectConsign\Feature\Admin\Mfa\AdminMfaPending;
use PerfectConsign\Feature\Admin\Mfa\AdminMfaStore;
use PerfectConsign\Feature\Admin\Mfa\SetupCode;
use PerfectConsign\Feature\Admin\Mfa\Totp;
use PerfectConsign\Feature\Inventory\InventoryStore;
use PerfectConsign\Feature\Inventory\ItemDates;
use PerfectConsign\Feature\Inventory\ItemEntry;
use PerfectConsign\Feature\Inventory\ItemSale;
use PerfectConsign\Feature\Offer\OfferController;
use PerfectConsign\Feature\Offer\OfferStore;
use PerfectConsign\Feature\Party\AssignmentBook;
use PerfectConsign\Feature\Party\BoothStays;
use PerfectConsign\Feature\Party\BoothWrite;
use PerfectConsign\Feature\Party\PartyStore;
use PerfectConsign\Feature\Party\VendorDraft;
use PerfectConsign\Feature\Vendor\VendorPreview;
use PerfectConsign\Feature\Password\PasswordChoice;
use PerfectConsign\Feature\Password\RecoveryAddress;
use PerfectConsign\Feature\Payout\OccupiedPack;
use PerfectConsign\Feature\Payout\PayoutRunLines;
use PerfectConsign\Feature\Payout\PayoutRunStore;
use PerfectConsign\Feature\Payout\PayoutSettler;
use PerfectConsign\Feature\Payout\PayoutStore;
use PerfectConsign\Feature\Payout\PayoutWindow;
use PerfectConsign\Feature\Payout\VendorClawback;
use PerfectConsign\Feature\Rent\LateFee;
use PerfectConsign\Feature\Rent\RentMoves;
use PerfectConsign\Feature\Rent\RentPayment;
use PerfectConsign\Feature\Rent\RentPosting;
use PerfectConsign\Feature\Rent\RentReceipt;
use PerfectConsign\Feature\Rent\RentSlip;
use PerfectConsign\Feature\Signup\SignupSlug;
use PerfectConsign\Feature\Staff\MallOwners;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Database\CatalogLinks;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Http\ProbeBanStore;
use PerfectConsign\Shared\Http\ProbeShape;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\RequestTrace;
use PerfectConsign\Shared\Http\ResponseSent;
use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Tenant\TenantRecord;
use PerfectConsign\Tests\Support\QuietAudit;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SignedAccountObligationTest extends TestCase
{
    private const ADMIN = '01900000-0000-7000-8000-0000000000aa';

    private const SCHEMA = 'tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testAWriteAndItsAuditShareOneTransaction(): void
    {
        $ran = false;
        QuietAudit::make($this->running())->within(static function () use (&$ran): void {
            $ran = true;
        });
        self::assertTrue($ran);

        $ceiling = new SaleCeiling(
            $this->stopping(),
            new Actor($this->memorySession()),
            QuietAudit::make($this->stopping()),
            new Request('POST', '/', [], []),
        );
        try {
            $ceiling->note('sale.pay', 'journal', 'journal-1');
            self::fail('a nameless account recorded a sale');
        } catch (DatabaseRefusal $error) {
            self::assertSame('the signed-in account is missing', $error->getMessage());
        }
        self::assertTrue((new SaleCeilingFlag($this->stopping()))->required());
    }

    public function testEnterAsRecordsTheOperatorOnTheStoreRow(): void
    {
        $session = new Session(null, false);
        $session->set('actor_role', 'owner');
        $session->set('actor_username', 'ada');
        $session->set('actor_account', self::ADMIN);
        $session->set('owner_name', 'ada');
        $session->set(AdminSession::ID, self::ADMIN);
        $session->set(AdminSession::EMAIL, 'platform-admin@example.test');
        $session->set(AdminSession::VERSION, '1');
        $pdo = $this->auditPdo();
        $enter = new AdminEnterAs(
            $pdo,
            new DatabaseRole($pdo),
            new LoginSession($session),
            new AdminSession($session),
            QuietAudit::admin($pdo, new Request('POST', '/enter', [], [], '', '', '127.0.0.1')),
            QuietAudit::make(new Statements($pdo), $session),
        );
        $record = \Closure::bind(function (TenantRecord $tenant, string $entityId): void {
            $this->mallAudit($tenant, $entityId);
        }, $enter, AdminEnterAs::class);
        $record(new TenantRecord(self::ADMIN, 'webprobe', self::SCHEMA, 'poster_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'Probe'), self::ADMIN);
        self::assertSame(1, $pdo->auditInserts);
    }

    public function testPricedPartyWritesStopWhenTheAuditTransactionStops(): void
    {
        $audit = QuietAudit::make($this->stopping());
        $book = new AssignmentBook($this->stopping());
        $parties = new PartyStore($this->stopping(), $audit, $book);
        $stays = new BoothStays($book, $audit);
        $booths = new BoothWrite($parties, $book, $audit);
        $this->stopped(static function () use ($parties): void {
            $parties->addPricedVendor(new VendorDraft([], null), 'hold');
        });
        $this->stopped(static function () use ($stays): void {
            $stays->addStay(self::ADMIN, self::ADMIN, '2026-01-01', '', null);
        });
        $this->stopped(static function () use ($stays): void {
            $stays->setStayEnd(self::ADMIN, '2026-02-01');
        });
        $this->stopped(static function () use ($stays): void {
            $stays->setStayRent(self::ADMIN, '2026-01-01', 10000);
        });
        $this->stopped(static function () use ($booths): void {
            $booths->addPricedBooth('A1', 'Front', null, null, null, null);
        });
        $this->stopped(static function () use ($booths): void {
            $booths->setBooth(self::ADMIN, 'A1', 'Front', null, null, null, null);
        });
        $this->stopped(static function () use ($booths): void {
            $booths->setBoothPrice(self::ADMIN, 10000);
        });
        self::assertNull($booths->askingRent(self::ADMIN));
    }

    public function testAnItemWithoutAVendorIsRefused(): void
    {
        $door = MallDoor::open($this, new Request('POST', '/gapmall/items', [], []));
        $store = new InventoryStore(
            $door->statements,
            new CatalogLinks($door->statements),
            $door->mall,
            QuietAudit::journal($door->statements, $door->actor, $door->session),
        );
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('That vendor is not in this mall.');
        $store->add(new ItemEntry(
            '',
            '',
            'missing',
            null,
            [],
            'Coat',
            new ItemSale(1000, 1, null, false),
            new ItemDates('2026-01-01', null),
            '',
        ), '2026-01-01');
    }

    public function testEndingADealUsesTheSignedInAuditTransaction(): void
    {
        $door = MallDoor::open($this, new Request('POST', '/gapmall/offers', [], []));
        $audit = QuietAudit::make($this->halting());
        $controller = new class (
            $door->app,
            new OfferStore($door->statements),
            $door->mall,
            new VendorPreview(
                $door->app,
                $door->actor,
                new PartyStore($door->statements, QuietAudit::make($door->statements), new AssignmentBook($door->statements)),
                $door->mall,
            ),
            $audit,
        ) extends OfferController {
            public function finish(string $slug, string $id): void
            {
                $this->endOffer($slug, $id, null, '/gapmall/offers');
            }
        };
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the signed-in write stopped');
        $controller->finish(MallDoor::SLUG, self::ADMIN);
    }

    public function testAWaiveWithoutAnAccountRollsNothingForward(): void
    {
        $statements = $this->stopping();
        $session = new Session(null, false);
        $session->delete('actor_role');
        $session->delete('actor_account');
        $session->delete('actor_username');
        $session->delete('owner_name');
        $pdo = $this->createStub(PDO::class);
        $pdo->method('inTransaction')->willReturn(false);
        $pdo->method('beginTransaction')->willReturn(true);
        $ledger = $this->createStub(LedgerStore::class);
        $payouts = new PayoutStore($pdo, $statements, new PayoutRunStore($statements, new OccupiedPack($statements)), new PayoutWindow());
        $receipts = new RentReceipt(
            $pdo,
            $statements,
            $payouts,
            new \PerfectConsign\Feature\Mall\MallPayoutSettings($statements),
            new RentPosting(
                $ledger,
                $payouts,
                new PayoutSettler(
                    $ledger,
                    $payouts,
                    new PayoutRunStore($statements, new OccupiedPack($statements)),
                    new PayoutRunLines($statements),
                    QuietAudit::make($statements),
                    new VendorClawback($ledger, $statements),
                ),
                new \PerfectConsign\Feature\Mall\MallPayoutSettings($statements),
                new RentMoves($statements, new \PerfectConsign\Feature\Mall\MallPayoutSettings($statements)),
            ),
            new RentSlip($statements),
            new RentPayment(),
            new LateFee($ledger, $statements),
            new SaleCeiling($statements, new Actor($session), QuietAudit::make($statements, $session), new Request('POST', '/', [], [])),
        );
        $this->expectException(DatabaseRefusal::class);
        $this->expectExceptionMessage('the signed-in account is missing');
        $receipts->waiveSigned(self::ADMIN, '', '', static function (): void {
        });
    }

    public function testAnOwnerChangeUndoesTheSavepointWhenTheWriteIsRefused(): void
    {
        $ran = [];
        $pdo = $this->createStub(PDO::class);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->method('exec')->willReturnCallback(static function (string $sql) use (&$ran): int {
            $ran[] = $sql;

            return 0;
        });
        $owners = new MallOwners($pdo, new DatabaseRole($pdo), new RecoveryAddress(), new PasswordChoice());
        $guarded = \Closure::bind(function (callable $work): mixed {
            return $this->guarded($work);
        }, $owners, MallOwners::class);
        try {
            $guarded(static function (): void {
                throw new \InvalidArgumentException('That sign-in name is already used.');
            });
            self::fail('a duplicate owner was stored');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('That sign-in name is already used.', $error->getMessage());
        }
        self::assertContains('ROLLBACK TO SAVEPOINT mall_owner', $ran);
        self::assertContains('RELEASE SAVEPOINT mall_owner', $ran);
    }

    public function testActivityCsvNamesTheSignedInRow(): void
    {
        $door = MallDoor::open($this, new Request('GET', '/gapmall/activity.csv', [], []));
        $rows = new class ($door->pdo) extends Statements {
            public function bound(string $sql, array $params): array
            {
                if (str_contains($sql, 'AS total')) {
                    return [['total' => 1]];
                }
                if (str_contains($sql, 'audit_events')) {
                    return [[
                        'id' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
                        'at' => '2026-11-20 09:00',
                        'username' => 'ada',
                        'actor' => 'ada',
                        'role' => 'owner',
                        'action' => 'settings.edit',
                        'entity_type' => 'company',
                        'entity_id' => 'company',
                        'authority_username' => null,
                        'entered_by_email' => null,
                        'client_address' => '127.0.0.1',
                        'account_id' => MallDoor::ACCOUNT,
                    ]];
                }

                return [];
            }
        };
        $this->expectOutputRegex('/settings\\.edit/');
        try {
            (new ActivityController(
                $door->app,
                new ActivityLog($rows),
                new ActivityPeople($rows, new MallOwners(
                    $door->pdo,
                    $door->roles,
                    new RecoveryAddress(),
                    new PasswordChoice(),
                )),
                new ActivityHref($rows),
                $door->actor,
                $door->mall,
            ))->csv(MallDoor::SLUG);
            self::fail('the activity file did not finish');
        } catch (ResponseSent $sent) {
            self::assertSame('activity', $sent->getMessage());
        }
    }

    public function testAuthenticatorScreensRefuseAFinishedSession(): void
    {
        $controller = $this->mfaController(false);
        foreach (
            [
                static fn (AdminMfaController $page): mixed => $page->enrollForm(),
                static fn (AdminMfaController $page): mixed => $page->codesForm(),
                static fn (AdminMfaController $page): mixed => $page->challengeForm(),
            ] as $call
        ) {
            try {
                $call($controller);
                self::fail('an authenticator screen stayed open');
            } catch (ResponseSent $sent) {
                self::assertSame('redirect', $sent->getMessage());
            }
        }
        $posted = $this->mfaController(true);
        foreach (
            [
                static fn (AdminMfaController $page): mixed => $page->enrollSubmit(),
                static fn (AdminMfaController $page): mixed => $page->codesSubmit(),
                static fn (AdminMfaController $page): mixed => $page->challengeSubmit(),
            ] as $call
        ) {
            ob_start();
            try {
                $call($posted);
                self::fail('an authenticator post stayed open');
            } catch (ResponseSent $sent) {
                self::assertSame('csrf', $sent->getMessage());
            } finally {
                $page = ob_get_clean();
            }
            self::assertIsString($page);
            self::assertStringContainsString('The form expired.', $page);
        }
    }

    public function testTheOperatorDoorAndStoreFileAreNamed(): void
    {
        self::assertTrue(AdminPath::isPublicAdmin('/admin/audit'));
        self::assertFalse(AdminPath::isPublicAdmin('/ci-admin-path-7f3a9c/tenants'));
        $path = new AdminPath('/ci-admin-path-7f3a9c');
        $routes = AdminRouter::definedRoutes(dirname(__DIR__, 3) . '/src/Feature', $path);
        self::assertNotSame([], $routes);
        $http = \Closure::bind(static function (array $methods): array {
            return self::httpMethods($methods);
        }, null, AdminRouter::class);
        self::assertSame(['GET'], $http(['', 'GET']));
        $storeFile = \Closure::bind(static function (string $file): bool {
            return self::storeFile($file);
        }, null, ProbeShape::class);
        self::assertTrue($storeFile('/webprobe/activity.csv'));
        self::assertFalse($storeFile('/webprobe/a.env'));
        $request = new Request('GET', '/webprobe/activity.csv', [], [], '', '', '', [], new RequestTrace(target: '/webprobe/activity.csv'));
        self::assertFalse(ProbeShape::matches($request));
        self::assertSame('probe-mercantile', SignupSlug::fromName('Probe Mercantile'));
    }

    public function testAuthenticatorResultsNameTheSignedInAdmin(): void
    {
        self::assertTrue(AdminSignInResult::enroll()->needsEnrollment());
        self::assertFalse(AdminSignInResult::enroll()->isSignedIn());
        self::assertTrue(AdminSignInResult::challenge()->accepted());
        self::assertFalse(AdminSignInResult::challenge()->isSignedIn());
        self::assertTrue(AdminSignInResult::signedIn()->isSignedIn());
        self::assertTrue(AdminSignInResult::signedIn()->accepted());
        self::assertFalse(AdminSignInResult::signedIn()->needsEnrollment());
        self::assertFalse(AdminSignInResult::refused()->accepted());
        self::assertTrue(AdminMfaAttempt::success()->opened());
        self::assertFalse(AdminMfaAttempt::invalid('That code is wrong.')->opened());
        self::assertSame(20, strlen(Totp::generateSecret()));

        $session = $this->memorySession();
        $pending = new AdminMfaPending($session, new AdminSession($session));
        $pending->beginEnrollment(self::ADMIN, 'platform-admin@example.test');
        self::assertSame('/mfa/enroll', $pending->target(time()));
        self::assertIsArray($pending->enrollment(time()));
        $pending->rememberCodes(['aaaa-bbbb']);
        self::assertSame(['aaaa-bbbb'], $pending->codes());
        $pending->beginChallenge(self::ADMIN, 'platform-admin@example.test');
        self::assertSame('/mfa', $pending->target(time()));
        self::assertSame(self::ADMIN, $pending->challenge(time())['id'] ?? null);
        self::assertNull((new AdminSession($this->memorySession()))->pendingTarget(time()));
    }

    public function testAnAuthenticatorRecordAndAProbeBanAreStored(): void
    {
        $sql = '';
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('rowCount')->willReturn(1);
        $statement->method('fetchColumn')->willReturnCallback(static function () use (&$sql): string|false {
            if (str_contains($sql, 'credentials_version')) {
                return '4';
            }
            if (str_contains($sql, 'mfa_secret') && !str_contains($sql, 'UPDATE')) {
                return 'v1:secret';
            }

            return self::ADMIN;
        });
        $statement->method('fetchAll')->willReturn([[
            'address' => '127.0.0.1',
            'banned_at' => '2026-11-20 09:00:00+00',
            'banned_until' => '2026-11-20 09:15:00+00',
            'active' => 'no',
            'strikes' => '1',
            'reason' => 'probe',
            'path' => '/.env',
        ]]);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(static function (string $query) use (&$sql, $statement): PDOStatement {
            $sql = $query;

            return $statement;
        });
        $pdo->method('query')->willReturn($statement);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(false);
        $request = new Request('POST', '/mfa', [], [], '', '', '127.0.0.1');
        $store = new AdminMfaStore($pdo, QuietAudit::admin($pdo, $request));
        self::assertTrue($store->hasSecret(self::ADMIN));
        self::assertSame('4', $store->version(self::ADMIN));
        self::assertTrue($store->enroll(self::ADMIN, 'platform-admin@example.test', 'v1:secret', 3, ['hash']));
        self::assertTrue($store->advance(self::ADMIN, 4));
        self::assertTrue($store->burn(self::ADMIN, 'hash'));
        $failures = new AdminMfaFailures(
            $pdo,
            $request,
            $this->createStub(LoggerInterface::class),
            QuietAudit::admin($pdo, $request),
            new \PerfectConsign\Bootstrap\SignInFailureRetention($pdo),
        );
        $failures->replay(self::ADMIN, 'platform-admin@example.test', ['reason' => 'replay']);
        $failures->clearEmail('platform-admin@example.test');
        $bans = new ProbeBanStore($pdo);
        self::assertTrue($bans->lift('127.0.0.1'));
        self::assertSame('127.0.0.1', $bans->listBans()[0]['address']);
    }

    private function memorySession(): Session
    {
        return new class extends Session {
            /** @var array<string, mixed> */
            private array $bag = [];

            public function __construct()
            {
            }

            public function get(string $key): mixed
            {
                return $this->bag[$key] ?? null;
            }

            public function set(string $key, mixed $value): void
            {
                $this->bag[$key] = $value;
            }

            public function delete(string $key): void
            {
                unset($this->bag[$key]);
            }

            public function regenerateId(bool $deleteOldSession = true): void
            {
            }
        };
    }

    private function mfaController(bool $post): AdminMfaController
    {
        $session = new Session(null, false);
        if (!$post) {
            $session->set(AdminSession::ID, self::ADMIN);
            $session->set(AdminSession::EMAIL, 'platform-admin@example.test');
            $session->set(AdminSession::VERSION, '1');
        }
        $admins = new AdminSession($session);
        $pdo = $this->createStub(PDO::class);
        $path = new AdminPath('/ci-admin-path-7f3a9c');
        $app = new AdminApp(
            $pdo,
            $session,
            new View(dirname(__DIR__, 3) . '/src'),
            new Request($post ? 'POST' : 'GET', $path->url('/mfa'), [], []),
            $this->createStub(LoggerInterface::class),
            $admins,
            new LoginSession($session),
            $path,
        );
        $pending = new AdminMfaPending($session, $admins);
        $request = new Request('POST', '/', [], [], '', '', '127.0.0.1');
        $store = new AdminMfaStore($pdo, QuietAudit::admin($pdo, $request));
        $failures = new AdminMfaFailures(
            $pdo,
            $request,
            $this->createStub(LoggerInterface::class),
            QuietAudit::admin($pdo, $request),
            new \PerfectConsign\Bootstrap\SignInFailureRetention($pdo),
        );
        $login = new AdminMfaLogin($store, $admins, QuietAudit::admin($pdo, $request), $failures);

        return new AdminMfaController(
            $app,
            $pending,
            new AdminMfaEnrollment($pending, $store, $failures, $login, $this->createStub(LoggerInterface::class)),
            new AdminMfaChallenge($pending, $store, $failures, $login, $this->createStub(LoggerInterface::class)),
            new SetupCode($this->createStub(LoggerInterface::class)),
        );
    }

    private function stopped(callable $work): void
    {
        try {
            $work();
            self::fail('a signed-in write continued without its audit transaction');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('the signed-in write stopped', $error->getMessage());
        }
    }

    private function halting(): Statements
    {
        return new class ($this->createStub(PDO::class)) extends Statements {
            public function transaction(callable $work): void
            {
                throw new \RuntimeException('the signed-in write stopped');
            }
        };
    }

    private function stopping(): Statements
    {
        return new class ($this->createStub(PDO::class)) extends Statements {
            public function transaction(callable $work): void
            {
                throw new \InvalidArgumentException('the signed-in write stopped');
            }

            public function bound(string $sql, array $params): array
            {
                return [];
            }

            public function rows(string $sql): array
            {
                return [['require_second_signature' => true]];
            }
        };
    }

    private function running(): Statements
    {
        return new class ($this->createStub(PDO::class)) extends Statements {
            public function transaction(callable $work): void
            {
                $work();
            }
        };
    }

    private function auditPdo(): PDO
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('rowCount')->willReturn(1);
        $pdo = new class ($statement) extends PDO {
            public int $auditInserts = 0;

            public function __construct(private readonly PDOStatement $statement)
            {
                parent::__construct('sqlite::memory:');
            }

            public function exec(string $statement): int|false
            {
                return 0;
            }

            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                $this->auditInserts++;

                return $this->statement;
            }
        };

        return $pdo;
    }
}
