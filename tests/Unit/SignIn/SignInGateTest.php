<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Unit\SignIn;

use PerfectConsign\Bootstrap\SignInAccount;
use PerfectConsign\Bootstrap\SignInAlert;
use PerfectConsign\Bootstrap\SignInCheck;
use PerfectConsign\Bootstrap\SignInDevice;
use PerfectConsign\Bootstrap\SignInGate;
use PerfectConsign\Bootstrap\SignInLocked;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Mail\MailOutbox;
use PerfectConsign\Tests\Support\ScriptPdo;
use PerfectConsign\Tests\Support\SignInTestKit;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class SignInGateTest extends TestCase
{
    private const TENANT = '01900000-0000-7000-8000-0000000000aa';

    public function testAnAccountIsNamedByItsDoor(): void
    {
        self::assertSame(['admin', 'boss@example.test', null, null], SignInAccount::admin(' Boss@Example.test ')->parameters());
        self::assertSame(['store', null, self::TENANT, 'ada'], SignInAccount::store(self::TENANT, 'ada')->parameters());
        self::assertSame(['store', 'ada@example.test', self::TENANT, 'ada'], SignInAccount::store(self::TENANT, 'ada', 'ADA@example.test')->parameters());
        self::assertSame(['store', 'x@example.test', null, null], SignInAccount::unknownEmail('X@example.test')->parameters());
    }

    public function testTheDeviceCookieIsRandomHashedAndNamedForTheScheme(): void
    {
        $plain = new SignInDevice([], false);
        self::assertSame('pc_device', $plain->name());
        self::assertNull($plain->hash());
        $claimed = $plain->claim();
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $claimed);
        self::assertSame($claimed, $plain->claim());
        self::assertSame($claimed, $plain->hash());

        $secure = new SignInDevice(['__Host-pc_device' => str_repeat('A', 43)], true);
        self::assertSame('__Host-pc_device', $secure->name());
        self::assertSame(hash('sha256', str_repeat('A', 43)), $secure->hash());
        self::assertNull((new SignInDevice(['pc_device' => 'short'], false))->hash());
        self::assertNull((new SignInDevice(['pc_device' => ['array']], false))->hash());
        self::assertNull((new SignInDevice(['__Host-pc_device' => str_repeat('A', 43)], false))->hash());
        (new SignInDevice([], false))->send(30);
    }

    public function testAnOpenAttemptRunsTheCheckAndARightPasswordSignsIn(): void
    {
        $pdo = $this->verdict('open');
        $gate = SignInTestKit::gate($pdo);
        self::assertTrue($gate->attempt(SignInAccount::admin('a@example.test'), 'x', static fn (): SignInCheck => SignInCheck::Right));
        self::assertFalse($gate->attempt(SignInAccount::admin('a@example.test'), 'x', static fn (): SignInCheck => SignInCheck::Wrong));
        $started = microtime(true);
        self::assertFalse($gate->attempt(SignInAccount::unknownEmail('n@example.test'), 'x', static fn (): SignInCheck => SignInCheck::NoAccount));
        self::assertGreaterThan(0.01, microtime(true) - $started, 'a miss checks the fixed hash');
        self::assertFalse($pdo->inTransaction());
        $gate->reset(SignInAccount::admin('a@example.test'));
    }

    public function testAnAdminPasswordIsCheckedAgainstTheStoredHash(): void
    {
        $gate = SignInTestKit::gate($this->verdict('open'));
        $hash = password_hash('right-pass', PASSWORD_DEFAULT);
        self::assertTrue($gate->attemptAdmin('a@example.test', 'right-pass', static fn (): ?string => $hash));
        self::assertFalse($gate->attemptAdmin('a@example.test', 'wrong-pass', static fn (): ?string => $hash));
        self::assertFalse($gate->attemptAdmin('a@example.test', 'right-pass', static fn (): ?string => null));
        self::assertFalse($gate->attemptAdmin('a@example.test', 'right-pass', static fn (): ?string => ''));
        $gate->resetAdmin('a@example.test');
        $gate->resetStore(self::TENANT, 'ada');
    }

    public function testARefusedAttemptNeverRunsTheCheck(): void
    {
        $gate = SignInTestKit::gate($this->verdict('locked_email'));
        try {
            $gate->attempt(SignInAccount::admin('a@example.test'), 'x', static function (): SignInCheck {
                throw new RuntimeException('the check ran');
            });
            self::fail('a locked attempt continued');
        } catch (SignInLocked $locked) {
            self::assertSame(SignInGate::LOCKED, $locked->getMessage());
        }
    }

    public function testAFirstLockFilesTheAlert(): void
    {
        putenv('STALL_PLATFORM_ADMIN_EMAIL=ops@example.test');
        try {
            $pdo = $this->verdict('locked_email', true);
            $pdo->onPrepare('file_outbound_mail', [['file_outbound_mail' => '01900000-0000-7000-8000-0000000000ff']]);
            $pdo->onPrepare('FROM platform.owners', [['email' => 'owner@example.test']]);
            $pdo->onPrepare('FROM platform.tenants', [['slug' => 'harbor']]);
            $this->expectException(SignInLocked::class);
            SignInTestKit::gate($pdo)->attempt(SignInAccount::store(self::TENANT, 'ada'), 'x', static fn (): SignInCheck => SignInCheck::Wrong);
        } finally {
            putenv('STALL_PLATFORM_ADMIN_EMAIL');
        }
    }

    public function testAFailedCheckRollsBackAndAMissingVerdictIsRefused(): void
    {
        $pdo = $this->verdict('open');
        try {
            SignInTestKit::gate($pdo)->attempt(SignInAccount::admin('a@example.test'), 'x', static function (): SignInCheck {
                throw new RuntimeException('the account could not be read');
            });
            self::fail('a broken check passed');
        } catch (RuntimeException $error) {
            self::assertSame('the account could not be read', $error->getMessage());
        }
        self::assertFalse($pdo->inTransaction());

        try {
            SignInTestKit::gate(new ScriptPdo())->attempt(SignInAccount::admin('a@example.test'), 'x', static fn (): SignInCheck => SignInCheck::Right);
            self::fail('a missing verdict passed');
        } catch (RuntimeException $error) {
            self::assertSame('the sign-in limits could not be read', $error->getMessage());
        }
    }

    public function testAnAttemptInsideATransactionLeavesItOpen(): void
    {
        $pdo = $this->verdict('open');
        $pdo->beginTransaction();
        self::assertTrue(SignInTestKit::gate($pdo)->attempt(SignInAccount::admin('a@example.test'), 'x', static fn (): SignInCheck => SignInCheck::Right));
        self::assertTrue($pdo->inTransaction());
        $pdo->rollBack();
        $pdo->onQuery('device_days', [['device_days' => '30']]);
        self::assertTrue(SignInTestKit::gate($pdo)->attempt(SignInAccount::admin('a@example.test'), 'x', static fn (): SignInCheck => SignInCheck::Right));
    }

    public function testTheAlertWordsNameTheAccount(): void
    {
        $pdo = new ScriptPdo();
        $pdo->onPrepare('file_outbound_mail', [['file_outbound_mail' => '01900000-0000-7000-8000-0000000000ff']]);
        $pdo->onPrepare('FROM platform.owners', []);
        $pdo->onPrepare('FROM platform.tenants', []);
        $alert = new SignInAlert($pdo, new MailOutbox($pdo));
        putenv('STALL_PLATFORM_ADMIN_EMAIL');
        $alert->send(SignInAccount::admin('boss@example.test'));
        $alert->send(SignInAccount::unknownEmail('nobody@example.test'));
        putenv('STALL_PLATFORM_ADMIN_EMAIL=ops@example.test');
        try {
            $alert->send(SignInAccount::store(self::TENANT, 'ada'));
            $alert->send(SignInAccount::admin('boss@example.test'));
        } finally {
            putenv('STALL_PLATFORM_ADMIN_EMAIL');
        }
        self::assertStringContainsString('your store', SignInAlert::ownerBody(SignInAccount::store(self::TENANT, 'ada')));
        self::assertStringContainsString('the platform admin', SignInAlert::ownerBody(SignInAccount::admin('boss@example.test')));
        self::assertStringContainsString('change your password', SignInAlert::ownerBody(SignInAccount::admin('boss@example.test')));
    }

    public function testTheGateUsesTheRequestAddressAndAgent(): void
    {
        $pdo = $this->verdict('open');
        $gate = new SignInGate(
            $pdo,
            new Request('POST', '/', [], [], '', '', '198.51.100.4'),
            new SignInDevice([], false),
            new SignInAlert($pdo, new MailOutbox($pdo)),
            new NullLogger(),
        );
        self::assertFalse($gate->attempt(SignInAccount::admin('a@example.test'), 'x', static fn (): SignInCheck => SignInCheck::Wrong));
    }

    private function verdict(string $verdict, bool $alerted = false): ScriptPdo
    {
        $pdo = new ScriptPdo();
        $pdo->onPrepare('sign_in_begin', [['verdict' => $verdict, 'trusted' => 'f', 'alerted' => $alerted ? 't' : 'f']]);

        return $pdo;
    }
}
