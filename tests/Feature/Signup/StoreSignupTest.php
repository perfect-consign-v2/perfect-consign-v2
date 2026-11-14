<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Signup;

use PerfectConsign\Feature\Admin\AdminAudit;
use PerfectConsign\Feature\Signup\PlatformSettings;
use PerfectConsign\Feature\Signup\SignupRateLimit;
use PerfectConsign\Feature\Signup\StoreSignup;
use PerfectConsign\Shared\Http\Request;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

final class StoreSignupTest extends TestCase
{
    public function testPublicSignupStopsWhenTheFeatureIsOff(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Store signup is not available.');
        $this->signup($this->createStub(PDO::class), $this->settings(false))->registerPublic(
            'Harbor',
            'owner@harbor.test',
            'password1',
            'password1',
            '127.0.0.1',
        );
    }

    public function testPublicSignupStopsWhenTheAddressIsLimited(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Too many signup attempts.');
        $this->signup($this->createStub(PDO::class), $this->settings('true'), $this->limits(5))->registerPublic(
            'Harbor',
            'owner@harbor.test',
            'password1',
            'password1',
            '127.0.0.1',
        );
    }

    public function testPublicSignupRefusesBadInputBeforeProvisioning(): void
    {
        $signup = $this->signup($this->createStub(PDO::class));
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
                $signup->registerPublic($name, $email, $password, $confirm, '127.0.0.1');
                self::fail($message);
            } catch (RuntimeException $error) {
                self::assertSame($message, $error->getMessage());
            }
        }
    }

    public function testPublicSignupRefusesATakenAddress(): void
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('fetchColumn')->willReturn(1);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('That store address is already taken.');
        $this->signup($pdo)->registerPublic(
            'Harbor',
            'owner@harbor.test',
            'password1',
            'password1',
            '127.0.0.1',
        );
    }

    public function testAStoreNameBecomesASlug(): void
    {
        $signup = $this->signup($this->createStub(PDO::class));
        self::assertSame('harbor-antique', $signup->slugFromName(' Harbor Antique '));
        self::assertSame('s123-shop', $signup->slugFromName('123 shop'));
        self::assertSame('s', $signup->slugFromName('---'));
        self::assertSame(str_repeat('a', 63), $signup->slugFromName(str_repeat('a', 80)));
        self::assertSame(str_repeat('a', 62), $signup->slugFromName(str_repeat('a', 62) . '-' . str_repeat('b', 20)));
        self::assertSame('s' . str_repeat('9', 62), $signup->slugFromName(str_repeat('9', 80)));
    }

    public function testAnAdminCreateStopsBeforeProvisioning(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Enter a store name.');
        $this->signup($this->createStub(PDO::class))->registerAdmin(
            '   ',
            'owner@harbor.test',
            'password1',
            'password1',
            '01900000-0000-7000-8000-0000000000aa',
            'admin@harbor.test',
        );
    }

    public function testAnAdminCreateRefusesATakenAddress(): void
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('fetchColumn')->willReturn(1);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('That store address is already taken.');
        $this->signup($pdo)->registerAdmin(
            'Harbor',
            'owner@harbor.test',
            'password1',
            'password1',
            '01900000-0000-7000-8000-0000000000aa',
            'admin@harbor.test',
        );
    }

    public function testAUsernameIsTakenFromTheMailbox(): void
    {
        $signup = $this->signup($this->createStub(PDO::class));
        $username = new ReflectionMethod(StoreSignup::class, 'usernameFromEmail');
        self::assertSame('owner', $username->invoke($signup, 'Owner@Harbor.test'));
        self::assertSame('u123-shop', $username->invoke($signup, '123 shop@harbor.test'));
        self::assertSame('u', $username->invoke($signup, '---@harbor.test'));
        self::assertSame(str_repeat('a', 63), $username->invoke($signup, str_repeat('A', 80) . '@harbor.test'));
        self::assertSame(
            str_repeat('a', 62),
            $username->invoke($signup, str_repeat('a', 62) . '-' . str_repeat('b', 20) . '@harbor.test'),
        );

        foreach (['@harbor.test', 'owner'] as $email) {
            try {
                $username->invoke($signup, $email);
                self::fail($email);
            } catch (RuntimeException $error) {
                self::assertSame('Enter a valid email address.', $error->getMessage());
            }
        }
    }

    public function testCreatingTheOwnerRecordsTheVerifiedMailbox(): void
    {
        $seen = [];
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturnCallback(static function (array $params) use (&$seen): bool {
            $seen[] = $params;

            return true;
        });
        $statement->method('rowCount')->willReturn(1);
        $admin = $this->createStub(PDO::class);
        $admin->method('prepare')->willReturn($statement);
        $signup = $this->signup($this->createStub(PDO::class));
        $create = new ReflectionMethod(StoreSignup::class, 'createOwner');
        $create->invoke($signup, $admin, 'cov-line', 'owner', 'password1', 'owner@harbor.test', true);
        $create->invoke($signup, $admin, 'cov-line', 'owner', 'password1', 'owner@harbor.test', false);

        self::assertSame(['true', 'false'], [$seen[0][3], $seen[1][3]]);
        $hash = $seen[0][1] ?? null;
        self::assertTrue(is_string($hash) && password_verify('password1', $hash));
        self::assertSame('cov-line', $seen[1][4]);
    }

    public function testCreatingTheOwnerRefusesADatabaseFailure(): void
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willThrowException(new PDOException('duplicate owner'));
        $admin = $this->createStub(PDO::class);
        $admin->method('prepare')->willReturn($statement);
        $create = new ReflectionMethod(StoreSignup::class, 'createOwner');
        try {
            $create->invoke(
                $this->signup($this->createStub(PDO::class)),
                $admin,
                'cov-line',
                'owner',
                'password1',
                'owner@harbor.test',
                true,
            );
            self::fail('a database failure was stored');
        } catch (RuntimeException $error) {
            self::assertSame('That store could not be created.', $error->getMessage());
            self::assertInstanceOf(PDOException::class, $error->getPrevious());
        }
    }

    public function testCreatingTheOwnerRefusesAMissingStore(): void
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('rowCount')->willReturn(0);
        $admin = $this->createStub(PDO::class);
        $admin->method('prepare')->willReturn($statement);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('That store was not found after provisioning.');
        (new ReflectionMethod(StoreSignup::class, 'createOwner'))->invoke(
            $this->signup($this->createStub(PDO::class)),
            $admin,
            'cov-line',
            'owner',
            'password1',
            'owner@harbor.test',
            true,
        );
    }

    public function testCompensationRemovesOnlyTheNamedSlug(): void
    {
        $wiped = null;
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturnCallback(static function (array $params) use (&$wiped): bool {
            $wiped = $params[0];

            return true;
        });
        $admin = $this->createStub(PDO::class);
        $admin->method('prepare')->willReturn($statement);
        (new ReflectionMethod(StoreSignup::class, 'compensate'))->invoke(
            $this->signup($this->createStub(PDO::class)),
            $admin,
            'cov-line',
        );

        self::assertSame('cov-line', $wiped);
    }

    public function testAFailedCompensationIsLogged(): void
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willThrowException(new RuntimeException('wipe refused'));
        $admin = $this->createStub(PDO::class);
        $admin->method('prepare')->willReturn($statement);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            'store signup compensate failed',
            $this->callback(static function (array $context): bool {
                return ($context['slug'] ?? null) === 'cov-line'
                    && ($context['message'] ?? null) === 'wipe refused';
            }),
        );
        $signup = new StoreSignup(
            $this->createStub(PDO::class),
            $this->settings('true'),
            $this->limits(0),
            new AdminAudit($this->createStub(PDO::class), new Request('GET', '/', [], [])),
            $logger,
        );
        (new ReflectionMethod(StoreSignup::class, 'compensate'))->invoke($signup, $admin, 'cov-line');
    }

    public function testTheTenantIdIsReadAfterProvisioning(): void
    {
        $found = $this->createStub(PDOStatement::class);
        $found->method('fetchColumn')->willReturn('01900000-0000-7000-8000-0000000000bb');
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($found);
        $tenant = new ReflectionMethod(StoreSignup::class, 'tenantId');
        self::assertSame(
            '01900000-0000-7000-8000-0000000000bb',
            $tenant->invoke($this->signup($pdo), 'cov-line'),
        );

        foreach ([false, ''] as $value) {
            $missing = $this->createStub(PDOStatement::class);
            $missing->method('fetchColumn')->willReturn($value);
            $pdo = $this->createStub(PDO::class);
            $pdo->method('prepare')->willReturn($missing);
            try {
                $tenant->invoke($this->signup($pdo), 'cov-line');
                self::fail('a missing tenant id was accepted');
            } catch (RuntimeException $error) {
                self::assertSame('That store was not found after provisioning.', $error->getMessage());
            }
        }
    }

    public function testOwnerIdIsAbsentWhenTheLookupMisses(): void
    {
        $missing = $this->createStub(PDOStatement::class);
        $missing->method('fetchColumn')->willReturn(false);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($missing);
        self::assertNull($this->signup($pdo)->ownerIdForSlug('harbor'));

        $found = $this->createStub(PDOStatement::class);
        $found->method('fetchColumn')->willReturn('01900000-0000-7000-8000-000000000001');
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($found);
        self::assertSame(
            '01900000-0000-7000-8000-000000000001',
            $this->signup($pdo)->ownerIdForSlug('harbor'),
        );
    }

    public function testAFreeAddressStopsWhenTheDatabaseCannotProvision(): void
    {
        $app = $this->createStub(PDO::class);
        $app->method('prepare')->willReturn($this->column(false));
        $admin = $this->createStub(PDO::class);
        $admin->method('query')->willReturn(false);
        $admin->method('prepare')->willReturn($this->column(false));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            'store signup failed',
            $this->callback(static fn (array $context): bool => ($context['slug'] ?? null) === 'harbor'),
        );
        $signup = new StoreSignup(
            $app,
            $this->settings('true'),
            $this->limits(0),
            new AdminAudit($this->createStub(PDO::class), new Request('GET', '/', [], [])),
            $logger,
        );
        $signup->useAdmin(static fn (): PDO => $admin);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('stall_backup is missing');
        $signup->registerAdmin(
            'Harbor',
            'owner@harbor.test',
            'password1',
            'password1',
            '01900000-0000-7000-8000-0000000000aa',
            'admin@harbor.test',
        );
    }

    public function testAnInstallerFailureThatIsNotARefusalIsWrapped(): void
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('fetchColumn')->willReturn(false);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $signup = new StoreSignup(
            $pdo,
            $this->settings('true'),
            $this->limits(0),
            new AdminAudit($this->createStub(PDO::class), new Request('GET', '/', [], [])),
            $this->createStub(LoggerInterface::class),
        );
        $signup->useAdmin(static fn (): PDO => $pdo);
        $signup->useInstaller(static function (): void {
            throw new \Exception('disk full');
        });
        try {
            $signup->registerAdmin(
                'Harbor',
                'owner@harbor.test',
                'password1',
                'password1',
                '01900000-0000-7000-8000-0000000000aa',
                'admin@harbor.test',
            );
            self::fail('an installer failure was stored');
        } catch (RuntimeException $error) {
            self::assertSame('That store could not be created.', $error->getMessage());
            self::assertInstanceOf(\Exception::class, $error->getPrevious());
        }
    }

    public function testAbandonWipesThroughTheAdminConnection(): void
    {
        $wiped = null;
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturnCallback(static function (array $params) use (&$wiped): bool {
            $wiped = $params[0];

            return true;
        });
        $admin = $this->createStub(PDO::class);
        $admin->method('prepare')->willReturn($statement);
        $signup = new StoreSignup(
            $this->createStub(PDO::class),
            $this->settings('true'),
            $this->limits(0),
            new AdminAudit($this->createStub(PDO::class), new Request('GET', '/', [], [])),
            $this->createStub(LoggerInterface::class),
        );
        $signup->useAdmin(static fn (): PDO => $admin);
        $signup->abandon('cov-line');

        self::assertSame('cov-line', $wiped);
    }

    public function testAbandonLogsWhenTheAdminConnectionFails(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            'store signup abandon failed',
            $this->callback(static fn (array $context): bool => ($context['message'] ?? null) === 'admin down'),
        );
        $signup = new StoreSignup(
            $this->createStub(PDO::class),
            $this->settings('true'),
            $this->limits(0),
            new AdminAudit($this->createStub(PDO::class), new Request('GET', '/', [], [])),
            $logger,
        );
        $signup->useAdmin(static function (): PDO {
            throw new RuntimeException('admin down');
        });
        $signup->abandon('cov-line');
    }

    private function column(mixed $value): PDOStatement
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchColumn')->willReturn($value);

        return $statement;
    }

    private function signup(
        PDO $pdo,
        ?PlatformSettings $settings = null,
        ?SignupRateLimit $limits = null,
    ): StoreSignup {
        return new StoreSignup(
            $pdo,
            $settings ?? $this->settings('true'),
            $limits ?? $this->limits(0),
            new AdminAudit($this->createStub(PDO::class), new Request('GET', '/', [], [])),
            $this->createStub(LoggerInterface::class),
        );
    }

    private function settings(mixed $value): PlatformSettings
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchColumn')->willReturn($value);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);

        return new PlatformSettings($pdo, $this->createStub(LoggerInterface::class));
    }

    private function limits(int $count): SignupRateLimit
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchColumn')->willReturn($count);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);

        return new SignupRateLimit($pdo);
    }
}
