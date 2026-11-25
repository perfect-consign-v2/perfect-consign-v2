<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Feature\Password\PasswordChoice;
use PerfectConsign\Shared\Http\ProbeBanStore;
use PerfectConsign\Shared\Http\ProbeShield;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\RequestTrace;
use PerfectConsign\Tests\Support\AdminDoor;
use PerfectConsign\Tests\Support\ProbeBanFixture;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\WebTestCase;
use PDO;
use PerfectApp\Logger\FileLogger;
use PHPUnit\Framework\Attributes\Group;

#[Group('smoke')]
final class ProbeBanHttpTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'platform-admin@example.test';

    private const ADMIN_PASSWORD = 'admin-pass-1';

    protected function setUp(): void
    {
        parent::setUp();
        ProbeBanFixture::releaseLoopback();
    }

    protected function tearDown(): void
    {
        ProbeBanFixture::releaseLoopback();
        parent::tearDown();
    }

    public function testAScannerOnTheHomePageBansWithoutASession(): void
    {
        $before = substr_count($this->probeLog(), 'probe banned');
        $first = $this->exchange('/', 'Mozilla/5.0 ZAP');
        self::assertSame(404, $first['status']);
        self::assertSame('', $first['body']);
        self::assertStringNotContainsString('set-cookie', strtolower($first['headers']));
        self::assertSame($before + 1, substr_count($this->probeLog(), 'probe banned'));

        $second = $this->exchange('/', 'Mozilla/5.0');
        self::assertSame(404, $second['status']);
        self::assertSame('', $second['body']);
        self::assertSame($before + 1, substr_count($this->probeLog(), 'probe banned'));
    }

    public function testTheTwentiethProbeBansAndAMissingAssetDoesNotCount(): void
    {
        $this->setStreak(18);
        $asset = $this->exchange('/assets/not-a-real-probe-file.css');
        self::assertSame(404, $asset['status']);
        self::assertSame(18, $this->streak());

        $page = $this->exchange('/');
        self::assertSame(200, $page['status']);
        self::assertSame(18, $this->streak());

        $once = $this->exchange('/wp-login.php');
        self::assertSame(404, $once['status']);
        self::assertSame('', $once['body']);
        self::assertSame(19, $this->streak());
        self::assertFalse($this->loopbackBanned());

        $twice = $this->exchange('/xmlrpc.php');
        self::assertSame(404, $twice['status']);
        self::assertTrue($this->loopbackBanned());
        $after = $this->exchange('/');
        self::assertSame(404, $after['status']);
        self::assertSame('', $after['body']);
    }

    public function testPhonesAndBrowsersAskingForTheirOwnFilesNeverCount(): void
    {
        foreach (
            [
                '/apple-touch-icon-120x120.png',
                '/apple-touch-icon-152x152-precomposed.png',
                '/favicon-32x32.png',
                '/.well-known/change-password',
                '/.well-known/appspecific/com.chrome.devtools.json',
                '/site.webmanifest',
                '/manifest.json',
                '/browserconfig.xml',
            ] as $path
        ) {
            for ($asked = 1; $asked <= 25; $asked++) {
                $miss = $this->exchange($path);
                self::assertSame(404, $miss['status'], $path);
                self::assertSame('', $miss['body'], $path);
            }
        }
        self::assertNull($this->streak());
        self::assertFalse($this->loopbackBanned());
        self::assertSame(200, $this->exchange('/')['status']);
    }

    public function testASignedInOwnerOpeningMissingPagesIsNeverCounted(): void
    {
        $client = self::client();
        $signedIn = $client->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        self::assertSame(200, $signedIn->status);
        for ($opened = 1; $opened <= 25; $opened++) {
            $missing = $client->get('/' . ProbeMall::SLUG . '/inventory/01900000-0000-7000-8000-0000000000' . sprintf('%02d', $opened) . '/edit');
            self::assertSame(404, $missing->status);
            $unknown = $client->get('/' . ProbeMall::SLUG . '/no-such-page-' . (string) $opened);
            self::assertSame(404, $unknown->status);
        }
        self::assertSame(404, $client->get('/admin')->status);
        self::assertSame(404, $client->get('/wp-login.php')->status);
        self::assertNull($this->streak());
        self::assertFalse($this->loopbackBanned());
        self::assertSame(200, $client->get('/' . ProbeMall::SLUG . '/dashboard')->status);
    }

    public function testPublicAdminIsAnEmpty404AndThePrefixStillSignsIn(): void
    {
        $admin = $this->exchange('/admin');
        $login = $this->exchange('/admin/login');
        self::assertSame(404, $admin['status']);
        self::assertSame(404, $login['status']);
        self::assertSame('', $admin['body']);
        self::assertSame('', $login['body']);
        self::assertStringNotContainsString('Platform admin', $admin['body']);
        self::assertStringNotContainsString('Platform admin', $login['body']);

        ProbeBanFixture::releaseLoopback();
        $page = self::client()->get(AdminDoor::path('/login'));
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Platform admin', $page->body);
        self::assertStringContainsString('Sign in', $page->body);
    }

    public function testRobotsAndTheTouchIconAreRealFiles(): void
    {
        $robots = $this->exchange('/robots.txt');
        self::assertSame(200, $robots['status']);
        self::assertStringContainsString('User-agent: *', $robots['body']);
        self::assertStringContainsString('Disallow: /', $robots['body']);
        self::assertStringNotContainsString(AdminDoor::prefix(), $robots['body']);

        $icon = $this->exchange('/apple-touch-icon.png');
        $precomposed = $this->exchange('/apple-touch-icon-precomposed.png');
        self::assertSame(200, $icon['status']);
        self::assertSame(200, $precomposed['status']);
        self::assertStringStartsWith("\x89PNG", $icon['body']);
        self::assertSame($icon['body'], $precomposed['body']);
        self::assertStringContainsString('image/png', strtolower($icon['headers']));
    }

    public function testLiftingABanWritesTheAuditAndServesTheNextRequest(): void
    {
        $address = '203.0.113.50';
        ProbeBanFixture::release($address);
        $pdo = ProbeBanFixture::connection();
        $this->seedAdmin($pdo);
        $store = new ProbeBanStore($pdo);
        self::assertTrue($store->insertBan($address, 'streak', 'browser', '/missing'));
        $before = $this->liftCount($pdo, $address);
        try {
            $client = self::client();
            $signedIn = AdminDoor::signIn($client, self::ADMIN_EMAIL, self::ADMIN_PASSWORD);
            self::assertSame(200, $signedIn->status);
            $listed = $client->get(AdminDoor::path('/log'));
            self::assertStringContainsString($address, $listed->body);
            $lifted = $client->post(AdminDoor::path('/log/lift'), ['address' => $address]);
            self::assertStringContainsString('Lifted the ban', $lifted->body);
            self::assertSame($before + 1, $this->liftCount($pdo, $address));
            self::assertFalse($store->banned($address));
            $shield = new ProbeShield($store, new FileLogger($this->unusedLog()));
            self::assertFalse($shield->blocked(new Request(
                'GET',
                '/',
                [],
                [],
                '',
                'http://127.0.0.1',
                $address,
                [],
                new RequestTrace('', '/', 'mall.test', 'browser', ''),
            ), false));
        } finally {
            ProbeBanFixture::release($address);
        }
    }

    /**
     * @return array{status: int, headers: string, body: string}
     */
    private function exchange(string $path, string $agent = 'ProbeBanHttpTest'): array
    {
        if (!extension_loaded('curl')) {
            self::fail('the curl extension is required');
        }
        $curl = curl_init(self::$baseUrl . $path);
        if ($curl === false) {
            self::fail('curl did not start');
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_USERAGENT => $agent,
            CURLOPT_TIMEOUT => 30,
        ]);
        $raw = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        if (!is_string($raw) || !is_int($status) || !is_int($headerSize)) {
            self::fail('curl did not return a response');
        }

        return [
            'status' => $status,
            'headers' => substr($raw, 0, $headerSize),
            'body' => substr($raw, $headerSize),
        ];
    }

    private function setStreak(int $streak): void
    {
        $statement = ProbeBanFixture::connection()->prepare(
            'INSERT INTO platform.probe_streaks (client_address, streak, window_started_at, updated_at)
             VALUES (?, ?, clock_timestamp(), clock_timestamp())
             ON CONFLICT (client_address) DO UPDATE
             SET streak = EXCLUDED.streak,
                 window_started_at = EXCLUDED.window_started_at,
                 updated_at = EXCLUDED.updated_at'
        );
        $statement->execute(['127.0.0.1', $streak]);
    }

    private function streak(): ?int
    {
        $statement = ProbeBanFixture::connection()->prepare(
            'SELECT streak FROM platform.probe_streaks WHERE client_address = ?'
        );
        $statement->execute(['127.0.0.1']);
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

    private function loopbackBanned(): bool
    {
        return (new ProbeBanStore(ProbeBanFixture::connection()))->banned('127.0.0.1');
    }

    private function probeLog(): string
    {
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'probe.log';
        if (!is_file($path)) {
            return '';
        }
        $text = file_get_contents($path);

        return is_string($text) ? $text : '';
    }

    private function seedAdmin(PDO $pdo): void
    {
        $hash = (new PasswordChoice())->hash(self::ADMIN_PASSWORD);
        $statement = $pdo->prepare(
            'INSERT INTO platform.admins (id, email, password_hash)
             VALUES (uuidv7(), ?, ?)
             ON CONFLICT (email) DO UPDATE
                SET password_hash = EXCLUDED.password_hash'
        );
        $statement->execute([self::ADMIN_EMAIL, $hash]);
        AdminDoor::plant($pdo, self::ADMIN_EMAIL);
    }

    private function liftCount(PDO $pdo, string $address): int
    {
        $statement = $pdo->prepare(
            "SELECT COUNT(*) FROM platform.admin_audit
             WHERE action = 'probe_ban_lift'
               AND detail->>'address' = ?"
        );
        $statement->execute([$address]);
        $count = $statement->fetchColumn();

        return is_numeric($count) ? (int) $count : 0;
    }

    private function unusedLog(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'probe-http-');
        if ($path === false) {
            self::fail('the probe log could not be created');
        }

        return $path;
    }
}
