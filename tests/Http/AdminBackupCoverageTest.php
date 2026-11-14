<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PDO;
use PerfectConsign\Feature\Password\PasswordChoice;
use PerfectConsign\Tests\Support\FormClient;
use PerfectConsign\Tests\Support\ProbeMall;
use PerfectConsign\Tests\Support\ProbeServer;
use PerfectConsign\Tests\Support\WebTestCase;

final class AdminBackupCoverageTest extends WebTestCase
{
    private const ADMIN_EMAIL = 'platform-admin@example.test';

    private const ADMIN_PASSWORD = 'admin-pass-1';

    private static ?ProbeServer $good = null;

    public static function setUpBeforeClass(): void
    {
        \PHPUnit\Framework\TestCase::setUpBeforeClass();
        $root = dirname(__DIR__, 2);
        ProbeMall::ensure($root);
        self::$good = ProbeServer::startOn($root, 8106);
    }

    public static function tearDownAfterClass(): void
    {
        self::$good?->stop();
        self::$good = null;
        \PHPUnit\Framework\TestCase::tearDownAfterClass();
    }

    public function testBackupReturnPathsAndAFailedDownload(): void
    {
        $root = dirname(__DIR__, 2);
        $saved = getenv('STALL_BACKUP_PASSWORD');
        putenv('STALL_BACKUP_PASSWORD=wrong-backup-password');
        try {
            $broken = ProbeServer::startOn($root, 8104);
        } finally {
            if (is_string($saved) && $saved !== '') {
                putenv('STALL_BACKUP_PASSWORD=' . $saved);
            }
        }
        try {
            $client = new FormClient($broken->baseUrl());
            $client->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
            $signedOut = $client->post('/' . ProbeMall::SLUG . '/backup', ['return' => 'sign-out'], 60);
            self::assertStringContainsString('Sign out', $signedOut->body);
            self::assertStringContainsString('The backup could not be taken.', $signedOut->body);

            $settings = $client->post('/' . ProbeMall::SLUG . '/backup', ['return' => 'settings'], 60);
            self::assertStringContainsString('The backup could not be taken.', $settings->body);
            self::assertStringContainsString('Settings', $settings->body);

            $backupTab = $client->post('/' . ProbeMall::SLUG . '/backup', ['return' => 'settings/backup'], 60);
            self::assertStringContainsString('The backup could not be taken.', $backupTab->body);

            $other = $client->post('/' . ProbeMall::SLUG . '/backup', ['return' => 'register'], 60);
            self::assertStringContainsString('The backup could not be taken.', $other->body);
        } finally {
            $broken->stop();
        }
    }

    public function testASuccessfulBackupDownloads(): void
    {
        $client = new FormClient($this->goodUrl());
        $client->signIn(ProbeMall::SLUG, ProbeMall::USERNAME, ProbeMall::PASSWORD);
        $download = $client->post('/' . ProbeMall::SLUG . '/backup', ['return' => 'settings/backup'], 120);

        self::assertSame(200, $download->status);
        self::assertStringStartsWith('PK', $download->body);
    }

    public function testAnAdminCreatesAStore(): void
    {
        $root = dirname(__DIR__, 2);
        $pdo = $this->adminPdo();
        $this->seedAdmin($pdo);
        $slug = 'zcov' . bin2hex(random_bytes(3));
        $client = new FormClient($this->goodUrl());
        try {
            $client->get('/admin/login');
            $signedIn = $client->post('/admin/login', [
                'email' => self::ADMIN_EMAIL,
                'password' => self::ADMIN_PASSWORD,
            ]);
            self::assertStringContainsString('Tenants', $signedIn->body);
            $created = $client->post('/admin/tenants/create', [
                'store_name' => $slug,
                'email' => $slug . '@example.test',
                'password' => 'store-pass-1',
                'password_confirm' => 'store-pass-1',
            ], 180);
            self::assertStringContainsString('Created ' . $slug . '.', $created->body);
            self::assertStringContainsString($slug . '@example.test', $created->body);
        } finally {
            $this->removeCreatedStore($pdo, $slug);
        }
    }

    private function goodUrl(): string
    {
        if (self::$good === null) {
            self::fail('the probe server is not running');
        }

        return self::$good->baseUrl();
    }

    private function seedAdmin(PDO $pdo): void
    {
        $hash = (new PasswordChoice())->hash(self::ADMIN_PASSWORD);
        $statement = $pdo->prepare(
            'INSERT INTO platform.admins (id, email, password_hash)
             VALUES (uuidv7(), ?, ?)
             ON CONFLICT (email) DO UPDATE
                SET password_hash = EXCLUDED.password_hash,
                    credentials_version = platform.admins.credentials_version + 1'
        );
        $statement->execute([self::ADMIN_EMAIL, $hash]);
    }

    private function removeCreatedStore(PDO $pdo, string $slug): void
    {
        if (preg_match('/^zcov[a-f0-9]+$/', $slug) !== 1) {
            return;
        }
        $found = $pdo->prepare('SELECT 1 FROM platform.tenants WHERE slug = ?');
        $found->execute([$slug]);
        if ($found->fetchColumn() === false) {
            return;
        }
        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'wipe-tenant.php';
        wipeTenant($pdo, $slug, $slug);
    }

    private function adminPdo(): PDO
    {
        $host = getenv('STALL_DB_HOST');
        $port = getenv('STALL_DB_PORT');
        $name = getenv('STALL_DB_NAME');
        $user = getenv('STALL_DB_USER');
        $password = getenv('STALL_DB_PASSWORD');
        if (!is_string($host) || $host === '') {
            $host = '127.0.0.1';
        }
        if (!is_string($port) || $port === '') {
            $port = '5436';
        }
        if (!is_string($name) || $name === '') {
            $name = 'consign';
        }
        if (!is_string($user) || $user === '') {
            $user = 'stall';
        }
        if (!is_string($password) || $password === '') {
            $password = 'stall';
        }

        return new PDO(
            'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $name,
            $user,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
}
