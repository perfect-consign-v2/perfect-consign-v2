<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Backup;

use Psr\Log\LoggerInterface;

final class CommandProcess
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     */
    public function run(array $command, array $env, ?string $stdinFile, ?string $stdoutFile, string $redact): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, null, $env, ['bypass_shell' => true]);
        if ($process === false) {
            $this->logger->error('backup command could not start', ['tool' => $command[0] ?? '']);
            throw new BackupException('The backup could not be taken.');
        }
        $stdin = $pipes[0];
        $stdout = $pipes[1];
        $stderr = $pipes[2];
        if (!is_resource($stdin) || !is_resource($stdout) || !is_resource($stderr)) {
            throw new BackupException('The backup could not be taken.');
        }
        if ($stdinFile === null) {
            fclose($stdin);
        } else {
            $input = fopen($stdinFile, 'rb');
            if ($input === false) {
                fclose($stdin);
                fclose($stdout);
                fclose($stderr);
                proc_close($process);
                throw new BackupException('The backup could not be read.');
            }
            stream_copy_to_stream($input, $stdin);
            fclose($input);
            fclose($stdin);
        }
        if ($stdoutFile === null) {
            stream_get_contents($stdout);
            fclose($stdout);
        } else {
            $output = fopen($stdoutFile, 'wb');
            if ($output === false) {
                fclose($stdout);
                fclose($stderr);
                proc_close($process);
                throw new BackupException('The backup could not be written.');
            }
            stream_copy_to_stream($stdout, $output);
            fclose($output);
            fclose($stdout);
        }
        $errorText = stream_get_contents($stderr);
        fclose($stderr);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            $detail = is_string($errorText) ? $errorText : '';
            if ($redact !== '') {
                $detail = str_replace($redact, '[redacted]', $detail);
            }
            $this->logger->error('backup command failed', [
                'tool' => $command[0] ?? '',
                'exit' => $exitCode,
                'error' => $detail,
            ]);
            throw new BackupException('The backup could not be taken.');
        }
    }
}
