<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Backup;

use PerfectConsign\Feature\Backup\BackupConnection;
use PerfectConsign\Feature\Backup\BackupException;
use PerfectConsign\Feature\Backup\CommandProcess;
use PerfectConsign\Feature\Backup\PostgresTools;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

final class PostgresToolsTest extends TestCase
{
    public function testAnInvalidContainerNameIsRefused(): void
    {
        $before = $this->passwordFiles();
        $previous = getenv('STALL_PG_DUMP');
        putenv('STALL_PG_DUMP=docker:bad name');
        try {
            $tools = new PostgresTools(new CommandProcess(new NullLogger()));
            $tools->pgDump(
                new BackupConnection('127.0.0.1', '5436', 'consign', 'stall', 'secret'),
                ['--schema-only'],
                sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc-unused.sql'
            );
            self::fail('an invalid container was accepted');
        } catch (BackupException $error) {
            self::assertSame('STALL_PG_DUMP container name is invalid', $error->getMessage());
        } finally {
            if (is_string($previous) && $previous !== '') {
                putenv('STALL_PG_DUMP=' . $previous);
            }
        }
        self::assertSame($before, $this->passwordFiles());
    }

    public function testAMissingContainerStillRemovesThePasswordFile(): void
    {
        $before = $this->passwordFiles();
        $previous = getenv('STALL_PG_DUMP');
        putenv('STALL_PG_DUMP=docker:not-a-real-container');
        try {
            $tools = new PostgresTools(new CommandProcess(new NullLogger()));
            $tools->pgDump(
                new BackupConnection('127.0.0.1', '5436', 'consign', 'stall', 'secret'),
                ['--schema-only'],
                sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc-unused.sql'
            );
            self::fail('a missing container was accepted');
        } catch (BackupException $error) {
            self::assertSame('The backup could not be taken.', $error->getMessage());
        } finally {
            if (is_string($previous) && $previous !== '') {
                putenv('STALL_PG_DUMP=' . $previous);
            }
        }
        self::assertSame($before, $this->passwordFiles());
    }

    public function testAnUnsetDumpSettingUsesTheToolName(): void
    {
        $previous = getenv('STALL_PG_DUMP');
        putenv('STALL_PG_DUMP');
        try {
            $tools = new PostgresTools(new CommandProcess(new NullLogger()));
            $tools->pgDump(
                new BackupConnection('127.0.0.1', '5436', 'consign', 'stall', 'secret'),
                ['--version'],
                sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc-unused.sql'
            );
            self::fail('pg_dump was accepted without a configured binary');
        } catch (BackupException $error) {
            self::assertSame('The backup could not be taken.', $error->getMessage());
        } finally {
            if (is_string($previous) && $previous !== '') {
                putenv('STALL_PG_DUMP=' . $previous);
            }
        }
    }

    public function testALocalBinaryIsNotTreatedAsDocker(): void
    {
        $previous = getenv('STALL_PG_DUMP');
        putenv('STALL_PG_DUMP=' . PHP_BINARY);
        $log = new ToolLog();
        try {
            $tools = new PostgresTools(new CommandProcess($log));
            $tools->pgDump(
                new BackupConnection('127.0.0.1', '5436', 'consign', 'stall', 'secret'),
                ['--version'],
                sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc-unused.sql'
            );
            self::fail('php was accepted as pg_dump');
        } catch (BackupException $error) {
            self::assertSame('The backup could not be taken.', $error->getMessage());
            self::assertSame(PHP_BINARY, $log->tool);
        } finally {
            if (is_string($previous) && $previous !== '') {
                putenv('STALL_PG_DUMP=' . $previous);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function passwordFiles(): array
    {
        $found = [];
        foreach (['stall-pg-*', 'sta*.tmp'] as $pattern) {
            $matches = glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . $pattern);
            if (!is_array($matches)) {
                continue;
            }
            foreach ($matches as $match) {
                $found[$match] = $match;
            }
        }
        $files = array_values($found);
        sort($files);

        return $files;
    }
}

final class ToolLog extends AbstractLogger
{
    public string $tool = '';

    /**
     * @param mixed $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $tool = $context['tool'] ?? '';
        $this->tool = is_string($tool) ? $tool : '';
    }
}
