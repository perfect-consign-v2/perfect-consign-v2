<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Integration\Backup;

use PDO;
use PerfectConsign\Feature\Backup\BackupConnection;
use PerfectConsign\Feature\Backup\BackupException;
use PerfectConsign\Feature\Backup\BackupFile;
use PerfectConsign\Feature\Backup\BackupWorkspace;
use PerfectConsign\Feature\Backup\CommandProcess;
use PerfectConsign\Feature\Backup\DirectoryTree;
use PerfectConsign\Feature\Backup\PlatformSnapshot;
use PerfectConsign\Feature\Backup\PostgresTools;
use PerfectConsign\Feature\Backup\TenantBackup;
use PerfectConsign\Tests\Support\ProbeMall;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

final class TenantBackupPartsTest extends TestCase
{
    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        $tree = new DirectoryTree();
        foreach ($this->directories as $directory) {
            if (is_dir($directory)) {
                $tree->remove($directory);
            }
        }
    }

    public function testASlugThatIsNotAMallNameIsRefused(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));

        $this->expectException(BackupException::class);
        $this->backup()->create($this->connectionValue(), 'Bad Slug');
    }

    public function testTheArchiveNotesAreWrittenWithoutADump(): void
    {
        ProbeMall::ensure(dirname(__DIR__, 3));
        $pdo = $this->pdo();
        $backup = $this->backup();
        $pdo->exec('BEGIN ISOLATION LEVEL REPEATABLE READ');
        try {
            $snapshot = $this->call($backup, 'snapshot', $pdo);
            $id = $this->call($backup, 'backupId', $pdo);
            $version = $this->call($backup, 'version', $pdo);
            self::assertIsString($snapshot);
            self::assertMatchesRegularExpression('/^[0-9A-Fa-f]+(?:-[0-9A-Fa-f]+)+$/', $snapshot);
            self::assertIsString($id);
            self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $id);
            self::assertIsString($version);
            self::assertNotSame('', $version);
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        $directory = $this->keep($this->workspace()->make('parts-' . bin2hex(random_bytes(4))));
        $schemaPath = $directory . DIRECTORY_SEPARATOR . 'schema.sql';
        $written = file_put_contents($schemaPath, "-- schema\n");
        self::assertNotFalse($written);
        $hash = $this->call($backup, 'schemaHash', $schemaPath);
        self::assertIsString($hash);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);

        $tenant = (new PlatformSnapshot())->tenant($pdo, ProbeMall::SLUG);
        $owner = (new PlatformSnapshot())->owner($pdo, $tenant['id']);
        $this->call($backup, 'writeNotes', $pdo, $directory, $id, new \DateTimeImmutable('2026-10-07 00:00:00+00'), $tenant, $owner, $hash);
        $file = $this->call($backup, 'package', $directory, ProbeMall::SLUG, new \DateTimeImmutable('2026-10-07 00:00:00+00'), $id);
        self::assertInstanceOf(BackupFile::class, $file);
        self::assertFileExists($file->path);
        $backup->delete($file);
        self::assertDirectoryDoesNotExist($directory);

        $pdo->beginTransaction();
        $abandoned = $this->keep($this->workspace()->make('parts-abort-' . bin2hex(random_bytes(4))));
        $this->call($backup, 'abort', $pdo, $abandoned);
        self::assertFalse($pdo->inTransaction());
        self::assertDirectoryDoesNotExist($abandoned);

        $empty = $this->keep($this->workspace()->make('parts-empty-' . bin2hex(random_bytes(4))));
        $emptySchema = $empty . DIRECTORY_SEPARATOR . 'schema.sql';
        self::assertNotFalse(file_put_contents($emptySchema, ''));
        $this->expectException(BackupException::class);
        $this->call($backup, 'schemaHash', $emptySchema);
    }

    public function testTheDownloadBodyStopsOnAnEmptyRead(): void
    {
        $handle = fopen('php://memory', 'rb+');
        self::assertIsResource($handle);
        self::assertNotFalse(fwrite($handle, 'zip-bytes'));
        self::assertNotFalse(rewind($handle));
        ob_start();
        $this->call($this->backup(), 'writeBody', $handle);
        $body = ob_get_clean();
        fclose($handle);

        self::assertSame('zip-bytes', $body);
    }

    private function backup(): TenantBackup
    {
        return new TenantBackup(
            new NullLogger(),
            new PostgresTools(new CommandProcess(new NullLogger())),
            $this->workspace(),
            new PlatformSnapshot(),
        );
    }

    private function workspace(): BackupWorkspace
    {
        return new BackupWorkspace(new NullLogger(), new DirectoryTree());
    }

    private function keep(string $directory): string
    {
        $this->directories[] = $directory;

        return $directory;
    }

    private function call(TenantBackup $backup, string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod(TenantBackup::class, $method))->invoke($backup, ...$arguments);
    }

    private function connectionValue(): BackupConnection
    {
        return new BackupConnection(
            $this->env('STALL_DB_HOST'),
            $this->env('STALL_DB_PORT'),
            $this->env('STALL_DB_NAME'),
            $this->env('STALL_DB_USER'),
            $this->env('STALL_DB_PASSWORD'),
        );
    }

    private function pdo(): PDO
    {
        return new PDO(
            'pgsql:host=' . $this->env('STALL_DB_HOST') . ';port=' . $this->env('STALL_DB_PORT') . ';dbname=' . $this->env('STALL_DB_NAME'),
            $this->env('STALL_DB_USER'),
            $this->env('STALL_DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    private function env(string $name): string
    {
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            self::fail($name . ' is required');
        }

        return $value;
    }
}
