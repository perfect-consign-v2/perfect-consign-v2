<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Backup;

use PerfectConsign\Feature\Backup\BackupException;
use PerfectConsign\Feature\Backup\BackupWorkspace;
use PerfectConsign\Feature\Backup\DirectoryTree;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class BackupWorkspaceTest extends TestCase
{
    private string $directory = '';

    protected function tearDown(): void
    {
        if ($this->directory !== '' && is_dir($this->directory)) {
            (new DirectoryTree())->remove($this->directory);
        }
    }

    public function testAWorkspaceZipsTheThreeBackupFiles(): void
    {
        $workspace = new BackupWorkspace(new NullLogger(), new DirectoryTree());
        $this->directory = $workspace->make('workspace-' . bin2hex(random_bytes(4)));
        $schema = $this->directory . DIRECTORY_SEPARATOR . 'schema.sql';
        $platform = $this->directory . DIRECTORY_SEPARATOR . 'platform.sql';
        $manifest = $this->directory . DIRECTORY_SEPARATOR . 'manifest.txt';
        $workspace->write($schema, 'schema');
        $workspace->write($platform, 'platform');
        $workspace->write($manifest, 'manifest');
        $zip = $this->directory . DIRECTORY_SEPARATOR . 'backup.zip';
        $workspace->zip($zip, $schema, $platform, $manifest);

        self::assertFileExists($zip);
        self::assertGreaterThan(0, filesize($zip));
        self::assertTrue($workspace->delete($this->directory));
        self::assertDirectoryDoesNotExist($this->directory);
        self::assertTrue($workspace->delete($this->directory));
        $this->directory = '';
    }

    public function testAZipPathThatIsADirectoryIsRefused(): void
    {
        $workspace = new BackupWorkspace(new NullLogger(), new DirectoryTree());
        $this->directory = $workspace->make('workspace-' . bin2hex(random_bytes(4)));
        $schema = $this->directory . DIRECTORY_SEPARATOR . 'schema.sql';
        $workspace->write($schema, 'schema');

        $this->expectException(BackupException::class);
        $workspace->zip($this->directory, $schema, $schema, $schema);
    }

    public function testASecondWorkspaceForTheSameBackupIsTheSameDirectory(): void
    {
        $workspace = new BackupWorkspace(new NullLogger(), new DirectoryTree());
        $id = 'workspace-' . bin2hex(random_bytes(4));
        $this->directory = $workspace->make($id);

        self::assertSame($this->directory, $workspace->make($id));
    }

    public function testAMissingBackupFileIsRefused(): void
    {
        $workspace = new BackupWorkspace(new NullLogger(), new DirectoryTree());
        $this->directory = $workspace->make('workspace-' . bin2hex(random_bytes(4)));
        $schema = $this->directory . DIRECTORY_SEPARATOR . 'schema.sql';
        $platform = $this->directory . DIRECTORY_SEPARATOR . 'platform.sql';
        $manifest = $this->directory . DIRECTORY_SEPARATOR . 'manifest.txt';
        $workspace->write($platform, 'platform');
        $workspace->write($manifest, 'manifest');
        $zip = $this->directory . DIRECTORY_SEPARATOR . 'backup.zip';

        $this->expectException(BackupException::class);
        $workspace->zip($zip, $schema, $platform, $manifest);
    }

    public function testAMissingPlatformFileIsRefused(): void
    {
        $workspace = new BackupWorkspace(new NullLogger(), new DirectoryTree());
        $this->directory = $workspace->make('workspace-' . bin2hex(random_bytes(4)));
        $schema = $this->directory . DIRECTORY_SEPARATOR . 'schema.sql';
        $platform = $this->directory . DIRECTORY_SEPARATOR . 'platform.sql';
        $manifest = $this->directory . DIRECTORY_SEPARATOR . 'manifest.txt';
        $workspace->write($schema, 'schema');
        $workspace->write($manifest, 'manifest');
        $zip = $this->directory . DIRECTORY_SEPARATOR . 'backup.zip';

        $this->expectException(BackupException::class);
        $workspace->zip($zip, $schema, $platform, $manifest);
    }

    public function testAMissingManifestIsRefused(): void
    {
        $workspace = new BackupWorkspace(new NullLogger(), new DirectoryTree());
        $this->directory = $workspace->make('workspace-' . bin2hex(random_bytes(4)));
        $schema = $this->directory . DIRECTORY_SEPARATOR . 'schema.sql';
        $platform = $this->directory . DIRECTORY_SEPARATOR . 'platform.sql';
        $manifest = $this->directory . DIRECTORY_SEPARATOR . 'manifest.txt';
        $workspace->write($schema, 'schema');
        $workspace->write($platform, 'platform');
        $zip = $this->directory . DIRECTORY_SEPARATOR . 'backup.zip';

        $this->expectException(BackupException::class);
        $workspace->zip($zip, $schema, $platform, $manifest);
    }
}
