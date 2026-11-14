<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Backup;

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
use PerfectConsign\Feature\Backup\BackupClient;
use PerfectConsign\Feature\Backup\BackupConnection;
use PerfectConsign\Feature\Backup\BackupController;
use PerfectConsign\Feature\Backup\BackupException;
use PerfectConsign\Feature\Backup\BackupFile;
use PerfectConsign\Feature\Backup\BackupStore;
use PerfectConsign\Feature\Backup\BackupWorkspace;
use PerfectConsign\Feature\Backup\CommandProcess;
use PerfectConsign\Feature\Backup\DirectoryTree;
use PerfectConsign\Feature\Backup\NightlyCopy;
use PerfectConsign\Feature\Backup\PlatformSnapshot;
use PerfectConsign\Feature\Backup\PostgresTools;
use PerfectConsign\Feature\Backup\TenantBackup;
use PerfectConsign\Feature\Company\CompanyLines;
use PerfectConsign\Feature\Company\CompanyStore;
use PerfectConsign\Feature\Inventory\LabelCatalog;
use PerfectConsign\Feature\Mall\MallClock;
use PerfectConsign\Feature\Mall\MallController;
use PerfectConsign\Feature\Mall\MallDay;
use PerfectConsign\Feature\Mall\MallPayoutSettings;
use PerfectConsign\Feature\Mall\MallSettings;
use PerfectConsign\Shared\Auth\OwnerAccess;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\View;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class BackupDownloadBranchesTest extends TestCase
{
    private const SLUG = 'covbackup';

    public function testAClosedDownloadIsLoggedAndRemoved(): void
    {
        $messages = [];
        $deleted = false;
        $this->download('closed', $messages, $deleted, false);

        self::assertTrue($deleted);
        self::assertContains('owner backup download closed early', $messages);
    }

    public function testABackupExceptionAfterHeadersIsLogged(): void
    {
        $messages = [];
        $deleted = false;
        $this->download('late', $messages, $deleted, true);

        self::assertContains('owner backup failed after the download started', $messages);
    }

    public function testAnyOtherFailureAfterHeadersIsLogged(): void
    {
        $messages = [];
        $deleted = false;
        $this->download('disk-sent', $messages, $deleted, true);

        self::assertContains('owner backup failed', $messages);
    }

    public function testAnyOtherFailureBeforeHeadersShowsTheSignOutPage(): void
    {
        $messages = [];
        $deleted = false;
        $html = $this->download('disk', $messages, $deleted, false);

        self::assertContains('owner backup failed', $messages);
        self::assertStringContainsString('The backup could not be taken.', $html);
    }

    /**
     * @param list<string> $messages
     */
    private function download(string $mode, array &$messages, bool &$deleted, bool $headersSent): string
    {
        $request = new Request(
            'POST',
            '/' . self::SLUG . '/backup',
            [],
            ['csrf' => 'csrf-token', 'return' => 'sign-out'],
            '',
            'https://example.test',
            '203.0.113.10',
        );
        $built = $this->door($request);
        $controller = new BackupController(
            $built['app'],
            new BackupStore($built['pdo']),
            $this->client($mode, $deleted),
            new MallController(
                $built['app'],
                $built['mall'],
                new MallPayoutSettings($built['statements']),
                new BackupStore($built['pdo']),
                new NightlyCopy(),
                new LabelCatalog(),
            ),
            $this->logger($messages),
        );
        $controller->whenHeadersSent(static fn (): bool => $headersSent);
        ob_start();
        try {
            $controller->download(self::SLUG);
        } finally {
            $html = ob_get_clean();
        }

        return is_string($html) ? $html : '';
    }

    private function client(string $mode, bool &$deleted): BackupClient
    {
        $logger = $this->createStub(LoggerInterface::class);
        $client = new BackupClient(new TenantBackup(
            $logger,
            new PostgresTools(new CommandProcess($logger)),
            new BackupWorkspace($logger, new DirectoryTree()),
            new PlatformSnapshot(),
        ));
        $file = new BackupFile(
            '01900000-0000-7000-8000-0000000000cc',
            'backup.zip',
            sys_get_temp_dir(),
            4,
            str_repeat('ab', 32),
            'covbackup-backup.zip',
            '2026-11-12T00:00:00Z',
        );
        $client->script(
            static function (BackupConnection $connection, string $slug) use ($mode, $file): BackupFile {
                if ($connection->database === '' || $slug === '') {
                    throw new BackupException('The backup could not be taken.');
                }
                if ($mode === 'late') {
                    throw new BackupException('The backup could not be taken.');
                }
                if ($mode === 'disk' || $mode === 'disk-sent') {
                    throw new \RuntimeException('disk full');
                }

                return $file;
            },
            static function (BackupFile $archive) use ($mode): bool {
                return $mode !== 'closed' && $archive->byteSize > 0;
            },
            static function (BackupFile $archive) use (&$deleted): void {
                if ($archive->id !== '') {
                    $deleted = true;
                }
            },
        );

        return $client;
    }

    /**
     * @param list<string> $messages
     */
    private function logger(array &$messages): LoggerInterface
    {
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });
        $logger->method('error')->willReturnCallback(static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        return $logger;
    }

    /**
     * @return array{app: MallApp, mall: MallSettings, statements: Statements, pdo: PDO}
     */
    private function door(Request $request): array
    {
        $bag = [];
        $bag['csrf'] = 'csrf-token';
        $bag['owner_slug'] = self::SLUG;
        $bag['actor_role'] = 'owner';
        $bag['actor_account'] = '01900000-0000-7000-8000-0000000000aa';
        $bag['credentials_version'] = '1';
        $bag['owner_name'] = 'Ada';
        $bag['signed_in_at'] = (string) time();
        $bag['last_seen_at'] = (string) time();
        $session = $this->createStub(Session::class);
        $session->method('get')->willReturnCallback(static function (string $key) use (&$bag): mixed {
            return $bag[$key] ?? null;
        });
        $session->method('set')->willReturnCallback(static function (string $key, mixed $value) use (&$bag): void {
            $bag[$key] = is_string($value) ? $value : '';
        });
        $session->method('has')->willReturnCallback(static function (string $key) use (&$bag): bool {
            return array_key_exists($key, $bag);
        });
        $session->method('delete')->willReturnCallback(static function (string $key) use (&$bag): void {
            unset($bag[$key]);
        });
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willReturn(0);
        $pdo->method('prepare')->willReturnCallback(function (string $sql): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            $statement->method('execute')->willReturn(true);
            $statement->method('fetchAll')->willReturn([]);
            if (str_contains($sql, 'schema_name')) {
                $statement->method('fetch')->willReturn([
                    'id' => '01900000-0000-7000-8000-0000000000bb',
                    'slug' => self::SLUG,
                    'schema_name' => 'tenant_' . str_repeat('ab', 16),
                    'display_name' => 'Coverage',
                ]);
            }
            $statement->method('fetchColumn')->willReturn(str_contains($sql, 'latest_owner_backup') ? false : '1');

            return $statement;
        });
        $logger = $this->createStub(LoggerInterface::class);
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
        $door = new FrontDoor(
            $missing,
            new PriorClose($session, $request),
            $owner,
            new PostedRefusal($logger),
            new Actor($session),
            new CredentialStamp($pdo, $session, new DatabaseRole($pdo)),
        );
        $rows = $this->createStub(Statements::class);
        $rows->method('rows')->willReturn([[
            'time_zone' => 'America/Los_Angeles',
            'booth_width_ft' => 10,
            'booth_depth_ft' => 10,
            'floor_width_ft' => 40,
            'floor_depth_ft' => 40,
            'commission_bps' => 4000,
            'floor_days' => 60,
            'cash_round_minor' => 0,
            'tax_bps' => 800,
            'use_color' => false,
            'use_category' => false,
            'use_size' => false,
            'use_condition' => false,
            'use_department' => false,
            'use_brand' => false,
            'label_stock' => 'avery_8160',
            'booth_rentals_enabled' => false,
            'rent_cycle_day' => 1,
            'consignment_enabled' => true,
            'store_stock_enabled' => false,
            'receipt_footer' => '',
            'operating_mode' => 'both',
        ]]);
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

        return ['app' => $app, 'mall' => $mall, 'statements' => $rows, 'pdo' => $pdo];
    }
}
