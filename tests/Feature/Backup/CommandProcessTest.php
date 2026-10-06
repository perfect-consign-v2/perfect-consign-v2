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

    /**
     * @param mixed $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $detail = $context['error'] ?? '';
        $this->error = is_string($detail) ? $detail : '';
    }
}
