<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Http;

use PerfectConsign\Feature\Password\PasswordChoice;
use PerfectConsign\Shared\Http\ProbeBanStore;
use PerfectConsign\Tests\Support\ProbeBanFixture;
use PHPUnit\Framework\TestCase;

final class LiftProbeBanCommandTest extends TestCase
{
    private const ADDRESS = '203.0.113.60';

    private const ADMIN_EMAIL = 'probe-lift-cli@example.test';

    protected function setUp(): void
    {
        ProbeBanFixture::release(self::ADDRESS);
    }

    protected function tearDown(): void
    {
        ProbeBanFixture::release(self::ADDRESS);
    }

    public function testTheServerShellCanListAndLiftABan(): void
    {
        $pdo = ProbeBanFixture::connection();
        $this->seedAdmin();
        $store = new ProbeBanStore($pdo);
        self::assertTrue($store->insertBan(self::ADDRESS, 'streak', 'browser', '/x.php'));
        $before = $this->liftCount();

        $listed = $this->runLift([]);
        self::assertSame(0, $listed['code'], $listed['stderr']);
        self::assertStringContainsString(self::ADDRESS . '  until ', $listed['stdout']);

        $lifted = $this->runLift([self::ADDRESS, self::ADMIN_EMAIL]);
        self::assertSame(0, $lifted['code'], $lifted['stderr']);
        self::assertSame('lifted the ban for ' . self::ADDRESS . "\n", $lifted['stdout']);
        self::assertFalse($store->banned(self::ADDRESS));
        self::assertSame($before + 1, $this->liftCount());

        $again = $this->runLift([self::ADDRESS, self::ADMIN_EMAIL]);
        self::assertSame(0, $again['code'], $again['stderr']);
        self::assertSame(self::ADDRESS . " is not banned\n", $again['stdout']);
        self::assertSame($before + 1, $this->liftCount());
    }

    public function testABadAddressOrAnUnknownAdminIsRefused(): void
    {
        $missing = $this->runLift([self::ADDRESS]);
        self::assertSame(1, $missing['code']);
        self::assertStringContainsString('give the banned address, then the admin email', $missing['stderr']);

        $bad = $this->runLift(['not an address', self::ADMIN_EMAIL]);
        self::assertSame(1, $bad['code']);
        self::assertStringContainsString('give the banned address, then the admin email', $bad['stderr']);

        $unknown = $this->runLift([self::ADDRESS, 'nobody-here@example.test']);
        self::assertSame(1, $unknown['code']);
        self::assertStringContainsString('that platform admin was not found', $unknown['stderr']);
    }

    private function seedAdmin(): void
    {
        $statement = ProbeBanFixture::connection()->prepare(
            'INSERT INTO platform.admins (id, email, password_hash)
             VALUES (uuidv7(), ?, ?)
             ON CONFLICT (email) DO NOTHING'
        );
        $statement->execute([self::ADMIN_EMAIL, (new PasswordChoice())->hash('probe-lift-pass')]);
    }

    private function liftCount(): int
    {
        $statement = ProbeBanFixture::connection()->prepare(
            "SELECT COUNT(*) FROM platform.admin_audit
             WHERE action = 'probe_ban_lift'
               AND detail->>'address' = ?"
        );
        $statement->execute([self::ADDRESS]);
        $count = $statement->fetchColumn();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @param list<string> $arguments
     * @return array{code: int, stdout: string, stderr: string}
     */
    private function runLift(array $arguments): array
    {
        $root = dirname(__DIR__, 3);
        $script = $root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'lift-probe-ban.php';
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, $script, ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            null,
            ['bypass_shell' => true]
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'code' => proc_close($process),
            'stdout' => is_string($stdout) ? str_replace("\r\n", "\n", $stdout) : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }
}
