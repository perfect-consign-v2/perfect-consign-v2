<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Http;

use PerfectApp\Logger\FileLogger;
use PerfectConsign\Shared\Http\MissLog;
use PerfectConsign\Shared\Http\ProbeBanStore;
use PerfectConsign\Shared\Http\ProbeShield;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\RequestTrace;
use PerfectConsign\Shared\Http\RequestFactory;
use PerfectConsign\Tests\Support\ProbeBanFixture;
use PerfectConsign\Tests\Support\ProbeMall;
use PDO;
use PHPUnit\Framework\TestCase;

final class ProbeBanTest extends TestCase
{
    private string|false $origin = false;

    protected function setUp(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $this->origin = getenv('STALL_PUBLIC_ORIGIN');
        putenv('STALL_PUBLIC_ORIGIN=http://127.0.0.1');
    }

    protected function tearDown(): void
    {
        if ($this->origin === false) {
            putenv('STALL_PUBLIC_ORIGIN');
        } else {
            putenv('STALL_PUBLIC_ORIGIN=' . $this->origin);
        }
    }

    public function testAScannerUserAgentBansOnceAndTheRepeatIsSilent(): void
    {
        $address = '203.0.113.10';
        ProbeBanFixture::release($address);
        $log = $this->logFile();
        try {
            $shield = $this->shield($log);
            $first = $this->request($address, '/', 'Mozilla/5.0 ZAP');
            self::assertTrue($shield->blocked($first, false));
            self::assertTrue($this->store()->banned($address));
            $written = $this->logText($log);
            self::assertSame(1, substr_count($written, 'probe banned'));
            self::assertStringContainsString('scanner', $written);

            self::assertTrue($shield->blocked($this->request($address, '/', 'Mozilla/5.0'), false));
            self::assertSame(1, substr_count($this->logText($log), 'probe banned'));
        } finally {
            ProbeBanFixture::release($address);
            $this->dropLog($log);
        }
    }

    public function testNineteenProbesDoNotBanAndTheTwentiethDoes(): void
    {
        $address = '203.0.113.11';
        ProbeBanFixture::release($address);
        $log = $this->logFile();
        try {
            $shield = $this->shield($log);
            for ($hit = 1; $hit < 20; $hit++) {
                self::assertTrue($shield->blocked($this->request($address, '/wp-login.php'), false));
            }
            self::assertFalse($this->store()->banned($address));
            self::assertSame(19, $this->streak($address));

            self::assertTrue($shield->blocked($this->request($address, '/xmlrpc.php'), false));
            self::assertTrue($this->store()->banned($address));
            self::assertStringContainsString('streak', $this->logText($log));
            self::assertSame(15, $this->banMinutes($address));
        } finally {
            ProbeBanFixture::release($address);
            $this->dropLog($log);
        }
    }

    public function testASuccessfulResponseDoesNotForgetEarlierProbes(): void
    {
        $address = '203.0.113.12';
        ProbeBanFixture::release($address);
        try {
            $store = $this->store();
            self::assertFalse($store->hit($address, 'browser', '/x.php'));
            $shield = new ProbeShield($store, new FileLogger($this->logFile()));
            $shield->finished($this->request($address, '/'), 200, false, false);
            $shield->finished($this->request($address, '/'), 303, false, false);
            self::assertSame(1, $this->streak($address));
        } finally {
            ProbeBanFixture::release($address);
        }
    }

    public function testOnlyARouteMissCountsAfterTheResponse(): void
    {
        $address = '203.0.113.16';
        ProbeBanFixture::release($address);
        try {
            $shield = new ProbeShield($this->store(), new FileLogger($this->logFile()));
            $shield->finished($this->request($address, '/mall/inventory/missing'), 404, false, false);
            self::assertNull($this->streakOrNull($address));
            $shield->finished($this->request($address, '/mall/nowhere'), 404, true, true);
            self::assertNull($this->streakOrNull($address));
            $shield->finished($this->request($address, '/mall/nowhere'), 400, true, false);
            self::assertNull($this->streakOrNull($address));
            $shield->finished($this->request($address, '/mall/nowhere'), 404, true, false);
            self::assertSame(1, $this->streak($address));
        } finally {
            ProbeBanFixture::release($address);
        }
    }

    public function testSomeoneSignedInIsNeverCountedOrRefused(): void
    {
        $address = '203.0.113.17';
        ProbeBanFixture::release($address);
        $log = $this->logFile();
        try {
            $store = $this->store();
            $shield = $this->shield($log);
            for ($hit = 1; $hit <= 25; $hit++) {
                self::assertTrue($shield->blocked($this->request($address, '/admin'), true));
            }
            self::assertNull($this->streakOrNull($address));
            self::assertFalse($shield->blocked($this->request($address, '/', 'Mozilla/5.0 ZAP'), true));
            self::assertFalse($store->banned($address));

            self::assertTrue($store->insertBan($address, 'streak', 'browser', '/x.php'));
            self::assertFalse($shield->blocked($this->request($address, '/mall/pos'), true));
            self::assertTrue($shield->blocked($this->request($address, '/mall/pos'), false));
        } finally {
            ProbeBanFixture::release($address);
            $this->dropLog($log);
        }
    }

    public function testAnEndedBanLetsTheNextRequestThroughAndOnlyAScannerBanClimbs(): void
    {
        $address = '203.0.113.18';
        ProbeBanFixture::release($address);
        try {
            $store = $this->store();
            $shield = $this->shield($this->logFile());
            self::assertTrue($store->insertBan($address, 'streak', 'browser', '/x.php'));
            self::assertFalse($store->insertBan($address, 'streak', 'browser', '/x.php'));
            $this->endBan($address);
            self::assertFalse($store->banned($address));
            self::assertFalse($shield->blocked($this->request($address, '/'), false));
            $listed = $this->listed($address);
            self::assertFalse($listed['active']);
            self::assertSame(1, $listed['strikes']);

            self::assertTrue($store->insertBan($address, 'streak', 'browser', '/x.php'));
            self::assertSame(15, $this->banMinutes($address));
            $listed = $this->listed($address);
            self::assertTrue($listed['active']);
            self::assertSame(2, $listed['strikes']);

            $this->endBan($address);
            self::assertTrue($store->insertBan($address, 'scanner', 'sqlmap', '/'));
            self::assertSame(1440, $this->banMinutes($address));
            self::assertSame(3, $this->listed($address)['strikes']);
        } finally {
            ProbeBanFixture::release($address);
        }
    }

    public function testAnEmptyAddressNeverInsertsABan(): void
    {
        $before = $this->banCount();
        $shield = $this->shield($this->logFile());
        self::assertTrue($shield->blocked($this->request('', '/wp-login.php', 'Mozilla/5.0 ZAP'), false));
        self::assertSame($before, $this->banCount());
        $empty = $this->connection()->query("SELECT COUNT(*) FROM platform.probe_bans WHERE client_address = ''");
        self::assertNotFalse($empty);
        self::assertSame('0', (string) $empty->fetchColumn());
    }

    public function testAnUntrustedForwardedAddressIsNotTheBan(): void
    {
        $peer = '198.51.100.8';
        $forged = '203.0.113.99';
        ProbeBanFixture::release($peer);
        ProbeBanFixture::release($forged);
        $log = $this->logFile();
        try {
            $request = RequestFactory::from([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/',
                'REMOTE_ADDR' => $peer,
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (ZAP)',
                'HTTP_X_FORWARDED_FOR' => $forged,
            ], [], [], [], '');
            self::assertSame($peer, $request->address);
            self::assertTrue($this->shield($log)->blocked($request, false));
            self::assertTrue($this->store()->banned($peer));
            self::assertFalse($this->store()->banned($forged));
        } finally {
            ProbeBanFixture::release($peer);
            ProbeBanFixture::release($forged);
            $this->dropLog($log);
        }
    }

    public function testAQueryValueStaysOutOfTheLogContext(): void
    {
        $address = '203.0.113.13';
        ProbeBanFixture::release($address);
        $log = $this->logFile();
        try {
            $request = RequestFactory::from([
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/secret.php?token=super-secret-value',
                'REMOTE_ADDR' => $address,
                'HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; ZAP)',
            ], ['token' => 'super-secret-value'], [], [], '');
            $encoded = json_encode(MissLog::context($request, 'scanner'));
            self::assertIsString($encoded);
            self::assertStringNotContainsString('super-secret-value', $encoded);
            self::assertSame('/secret.php?token', $request->trace->target);
            self::assertTrue($this->shield($log)->blocked($request, false));
            self::assertStringNotContainsString('super-secret-value', $this->logText($log));
            self::assertStringContainsString('token', $this->logText($log));
        } finally {
            ProbeBanFixture::release($address);
            $this->dropLog($log);
        }
    }

    public function testLiftingABanLetsTheNextRequestThrough(): void
    {
        $address = '203.0.113.14';
        ProbeBanFixture::release($address);
        $log = $this->logFile();
        try {
            $store = $this->store();
            self::assertTrue($store->insertBan($address, 'streak', 'browser', '/missing'));
            $shield = new ProbeShield($store, new FileLogger($log));
            self::assertTrue($shield->blocked($this->request($address, '/'), false));
            self::assertTrue($store->lift($address));
            self::assertFalse($store->lift($address));
            self::assertFalse($store->banned($address));
            self::assertFalse($shield->blocked($this->request($address, '/'), false));
        } finally {
            ProbeBanFixture::release($address);
            $this->dropLog($log);
        }
    }

    public function testCurlAndPythonRequestsAreNotScanners(): void
    {
        $address = '203.0.113.15';
        ProbeBanFixture::release($address);
        try {
            $shield = $this->shield($this->logFile());
            self::assertFalse($shield->blocked($this->request($address, '/', 'curl/8.0'), false));
            self::assertFalse($shield->blocked($this->request($address, '/', 'python-requests/2.31'), false));
            self::assertFalse($this->store()->banned($address));
        } finally {
            ProbeBanFixture::release($address);
        }
    }

    private function shield(string $log): ProbeShield
    {
        return new ProbeShield($this->store(), new FileLogger($log));
    }

    private function store(): ProbeBanStore
    {
        return new ProbeBanStore($this->connection());
    }

    private function connection(): PDO
    {
        return ProbeBanFixture::connection();
    }

    private function request(string $address, string $path, string $agent = 'ProbeBanTest'): Request
    {
        return new Request(
            'GET',
            $path,
            [],
            [],
            '',
            'http://127.0.0.1',
            $address,
            [],
            new RequestTrace('text/html', $path, 'mall.test', $agent, ''),
        );
    }

    private function streak(string $address): int
    {
        $value = $this->streakOrNull($address);
        self::assertNotNull($value);

        return $value;
    }

    private function streakOrNull(string $address): ?int
    {
        $statement = $this->connection()->prepare(
            'SELECT streak FROM platform.probe_streaks WHERE client_address = ?'
        );
        $statement->execute([$address]);
        $streak = $statement->fetchColumn();
        if ($streak === false) {
            return null;
        }
        if (is_int($streak)) {
            return $streak;
        }
        if (is_string($streak) && ctype_digit($streak)) {
            return (int) $streak;
        }

        return null;
    }

    private function banMinutes(string $address): int
    {
        $statement = $this->connection()->prepare(
            'SELECT (extract(epoch FROM banned_until - banned_at) / 60)::int
             FROM platform.probe_bans WHERE client_address = ?'
        );
        $statement->execute([$address]);
        $minutes = $statement->fetchColumn();

        return is_numeric($minutes) ? (int) $minutes : -1;
    }

    private function endBan(string $address): void
    {
        $statement = $this->connection()->prepare(
            "UPDATE platform.probe_bans
             SET banned_at = clock_timestamp() - interval '2 hours',
                 banned_until = clock_timestamp() - interval '1 hour'
             WHERE client_address = ?"
        );
        $statement->execute([$address]);
    }

    /**
     * @return array{address: string, banned_at: string, banned_until: string, active: bool, strikes: int, reason: string, path: string}
     */
    private function listed(string $address): array
    {
        foreach ($this->store()->listBans() as $ban) {
            if ($ban['address'] === $address) {
                return $ban;
            }
        }
        self::fail('the ban is not listed');
    }

    private function banCount(): int
    {
        $statement = $this->connection()->query('SELECT COUNT(*) FROM platform.probe_bans');
        self::assertNotFalse($statement);
        $count = $statement->fetchColumn();

        return is_numeric($count) ? (int) $count : 0;
    }

    private function logFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'probe-ban-');
        if ($path === false) {
            self::fail('the probe log could not be created');
        }

        return $path;
    }

    private function logText(string $path): string
    {
        $text = file_get_contents($path);

        return is_string($text) ? $text : '';
    }

    private function dropLog(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
