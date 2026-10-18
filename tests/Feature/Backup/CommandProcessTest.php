<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Backup;

use PerfectConsign\Feature\Backup\BackupException;
use PerfectConsign\Feature\Backup\CommandProcess;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

final class CommandProcessTest extends TestCase
{
    public function testACommandCopiesInputAndOutput(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc-cmd-' . bin2hex(random_bytes(4));
        if (!mkdir($root, 0700) && !is_dir($root)) {
            self::fail('could not create a temp directory');
        }
        $input = $root . DIRECTORY_SEPARATOR . 'in.txt';
        $output = $root . DIRECTORY_SEPARATOR . 'out.txt';
        if (file_put_contents($input, 'hello') === false) {
            self::fail('could not write the command input');
        }
        $process = new CommandProcess(new NullLogger());
        $process->run(
            [PHP_BINARY, '-r', 'fwrite(STDOUT, stream_get_contents(STDIN));'],
            [],
            $input,
            $output,
            ''
        );
        self::assertSame('hello', file_get_contents($output));
        $process->run([PHP_BINARY, '-r', 'fwrite(STDOUT, "plain");'], [], null, null, '');
        $log = new RememberLog();
        try {
            (new CommandProcess($log))->run(
                [PHP_BINARY, '-r', 'fwrite(STDERR, "secret boom"); exit(2);'],
                [],
                null,
                null,
                'secret'
            );
            self::fail('a failing command was accepted');
        } catch (BackupException $error) {
            self::assertSame('The backup could not be taken.', $error->getMessage());
            self::assertStringContainsString('[redacted]', $log->error);
            self::assertStringNotContainsString('secret', $log->error);
        }
        $this->remove($output);
        $this->remove($input);
        rmdir($root);
    }

    public function testAMissingExecutableDoesNotWarn(): void
    {
        $log = new RememberLog();
        try {
            (new CommandProcess($log))->run(['stall-missing-dump'], [], null, null, '');
            self::fail('a missing executable was accepted');
        } catch (BackupException $error) {
            self::assertSame('The backup could not be taken.', $error->getMessage());
            self::assertSame('stall-missing-dump', $log->tool);
        }
    }

    public function testACommandOnThePathStillRuns(): void
    {
        $previous = getenv('PATH');
        putenv('PATH=' . PATH_SEPARATOR . dirname(PHP_BINARY));
        try {
            (new CommandProcess(new NullLogger()))->run(
                [basename(PHP_BINARY, '.exe'), '-r', 'exit(0);'],
                [],
                null,
                null,
                ''
            );
            self::assertTrue(true);
        } finally {
            if (is_string($previous) && $previous !== '') {
                putenv('PATH=' . $previous);
            }
        }
    }

    public function testAnUnsetPathRefusesACommand(): void
    {
        $previous = getenv('PATH');
        putenv('PATH');
        try {
            (new CommandProcess(new NullLogger()))->run(['stall-missing-dump'], [], null, null, '');
            self::fail('a command was accepted without PATH');
        } catch (BackupException $error) {
            self::assertSame('The backup could not be taken.', $error->getMessage());
        } finally {
            if (is_string($previous) && $previous !== '') {
                putenv('PATH=' . $previous);
            }
        }
    }

    public function testPhpIsFoundWhenTheExtensionListIsUnset(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(true);

            return;
        }
        $path = getenv('PATH');
        $extensions = getenv('PATHEXT');
        putenv('PATH=' . dirname(PHP_BINARY));
        putenv('PATHEXT');
        try {
            (new CommandProcess(new NullLogger()))->run(
                [basename(PHP_BINARY, '.exe'), '-r', 'exit(0);'],
                [],
                null,
                null,
                ''
            );
            self::assertTrue(true);
        } finally {
            $this->restoreEnv('PATH', $path);
            $this->restoreEnv('PATHEXT', $extensions);
        }
    }

    public function testAUselessExtensionListRefusesPhp(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(true);

            return;
        }
        $path = getenv('PATH');
        $extensions = getenv('PATHEXT');
        putenv('PATH=' . dirname(PHP_BINARY));
        putenv('PATHEXT=.NO');
        try {
            (new CommandProcess(new NullLogger()))->run(
                [basename(PHP_BINARY, '.exe'), '-r', 'exit(0);'],
                [],
                null,
                null,
                ''
            );
            self::fail('php was accepted under a useless extension list');
        } catch (BackupException $error) {
            self::assertSame('The backup could not be taken.', $error->getMessage());
        } finally {
            $this->restoreEnv('PATH', $path);
            $this->restoreEnv('PATHEXT', $extensions);
        }
    }

    public function testAMissingInputFileIsRefused(): void
    {
        set_error_handler(static fn (int $severity, string $message): bool => true);
        try {
            (new CommandProcess(new NullLogger()))->run(
                [PHP_BINARY, '-r', 'exit(0);'],
                [],
                sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc-missing-in-' . bin2hex(random_bytes(4)),
                null,
                ''
            );
            self::fail('a missing input file was accepted');
        } catch (BackupException $error) {
            self::assertSame('The backup could not be read.', $error->getMessage());
        } finally {
            restore_error_handler();
        }
    }

    public function testADirectoryAsOutputIsRefused(): void
    {
        set_error_handler(static fn (int $severity, string $message): bool => true);
        try {
            (new CommandProcess(new NullLogger()))->run(
                [PHP_BINARY, '-r', 'fwrite(STDOUT, "x");'],
                [],
                null,
                sys_get_temp_dir(),
                ''
            );
            self::fail('a directory was accepted as output');
        } catch (BackupException $error) {
            self::assertSame('The backup could not be written.', $error->getMessage());
        } finally {
            restore_error_handler();
        }
    }

    public function testAFileThatCannotRunIsRefused(): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc-note-' . bin2hex(random_bytes(4)) . '.txt';
        if (file_put_contents($path, 'x') === false) {
            self::fail('could not write a stand-in command');
        }
        try {
            (new CommandProcess(new NullLogger()))->run([$path], [], null, null, '');
            self::fail('a text file was accepted as a command');
        } catch (BackupException $error) {
            self::assertSame('The backup could not be taken.', $error->getMessage());
        } finally {
            if (is_file($path) && !unlink($path)) {
                self::fail('could not remove the stand-in command');
            }
        }
    }

    private function restoreEnv(string $name, string|false $previous): void
    {
        if (is_string($previous) && $previous !== '') {
            putenv($name . '=' . $previous);

            return;
        }
        putenv($name);
    }

    private function remove(string $path): void
    {
        if (is_file($path) && !unlink($path)) {
            self::fail('could not remove ' . $path);
        }
    }
}

final class RememberLog extends AbstractLogger
{
    public string $error = '';

    public string $tool = '';

    /**
     * @param mixed $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $detail = $context['error'] ?? '';
        $this->error = is_string($detail) ? $detail : '';
        $tool = $context['tool'] ?? '';
        if (is_string($tool) && $tool !== '') {
            $this->tool = $tool;
        }
    }
}
