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
    protected function setUp(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
    }

    public function testAScannerUserAgentBansOnceAndTheRepeatIsSilent(): void
    {
        $address = '203.0.113.10';
        ProbeBanFixture::release($address);
        $log = $this->logFile();
        try {
            $shield = $this->shield($log);
            $first = $this->request($address, '/', 'Mozilla/5.0 ZAP');
            self::assertTrue($shield->blocked($first));
            self::assertTrue($this->store()->banned($address));
            $written = $this->logText($log);
            self::assertSame(1, substr_count($written, 'probe banned'));
            self::assertStringContainsString('scanner', $written);

            self::assertTrue($shield->blocked($this->request($address, '/', 'Mozilla/5.0')));
            self::assertSame(1, substr_count($this->logText($log), 'probe banned'));
        } finally {
            ProbeBanFixture::release($address);
            $this->dropLog($log);
        }
    }

    public function testOneProbeDoesNotBanAndTheSecondDoes(): void
    {
        $address = '203.0.113.11';
        ProbeBanFixture::release($address);
        $log = $this->logFile();
        try {
            $shield = $this->shield($log);
            self::assertTrue($shield->blocked($this->request($address, '/wp-login.php')));
            self::assertFalse($this->store()->banned($address));
            self::assertSame(1, $this->streak($address));

            self::assertTrue($shield->blocked($this->request($address, '/xmlrpc.php')));
            self::assertTrue($this->store()->banned($address));
            self::assertStringContainsString('streak', $this->logText($log));
        } finally {
            ProbeBanFixture::release($address);
            $this->dropLog($log);
        }
    }

    public function testASuccessfulResponseClearsTheStreak(): void
    {
        $address = '203.0.113.12';
        ProbeBanFixture::release($address);
        try {
            $store = $this->store();
            self::assertSame(1, $store->increment($address));
            $shield = new ProbeShield($store, new FileLogger($this->logFile()));
            $shield->finished($this->request($address, '/'), 200);
            self::assertNull($this->streakOrNull($address));

            self::assertSame(1, $store->increment($address));
            $shield->finished($this->request($address, '/'), 303);
            self::assertNull($this->streakOrNull($address));
        } finally {
            ProbeBanFixture::release($address);
        }
    }

    public function testAnEmptyAddressNeverInsertsABan(): void
    {
        $before = $this->banCount();
        $shield = $this->shield($this->logFile());
        self::assertTrue($shield->blocked($this->request('', '/wp-login.php', 'Mozilla/5.0 ZAP')));
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
            self::assertTrue($this->shield($log)->blocked($request));
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
            self::assertTrue($this->shield($log)->blocked($request));
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
            self::assertTrue($shield->blocked($this->request($address, '/')));
            self::assertTrue($store->lift($address));
            self::assertFalse($store->banned($address));
            self::assertFalse($shield->blocked($this->request($address, '/')));
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
            self::assertFalse($shield->blocked($this->request($address, '/', 'curl/8.0')));
            self::assertFalse($shield->blocked($this->request($address, '/', 'python-requests/2.31')));
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
