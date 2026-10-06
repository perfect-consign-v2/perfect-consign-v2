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
        $started = $this->start($command, $env);
        try {
            $this->copyInput($started['stdin'], $stdinFile);
            $this->copyOutput($started['stdout'], $stdoutFile);
            $detail = $this->readError($started['stderr']);
            $exitCode = proc_close($started['process']);
            $this->requireSuccess($command, $exitCode, $detail, $redact);
        } catch (BackupException $error) {
            $this->close($started);
            throw $error;
        }
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     * @return array{process: resource, stdin: resource, stdout: resource, stderr: resource}
     */
    private function start(array $command, array $env): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $tool = $command[0] ?? '';
        if (!$this->present($tool)) {
            $this->logger->error('backup command could not start', ['tool' => $tool]);
            throw new BackupException('The backup could not be taken.');
        }
        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes, null, $env, ['bypass_shell' => true]);
        if ($process === false) {
            $this->logger->error('backup command could not start', ['tool' => $tool]);
            throw new BackupException('The backup could not be taken.');
        }
        $stdin = $pipes[0] ?? null;
        $stdout = $pipes[1] ?? null;
        $stderr = $pipes[2] ?? null;
        if (!is_resource($stdin) || !is_resource($stdout) || !is_resource($stderr)) {
            proc_close($process);
            throw new BackupException('The backup could not be taken.');
        }

        return [
            'process' => $process,
            'stdin' => $stdin,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }

    private function present(string $tool): bool
    {
        if ($tool === '' || $this->filePresent($tool)) {
            return $tool !== '';
        }
        $path = getenv('PATH');
        if (!is_string($path) || $path === '') {
            return false;
        }
        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            $directory = rtrim($directory, '/\\');
            if ($directory === '') {
                continue;
            }
            foreach ($this->extensions() as $extension) {
                if ($this->filePresent($directory . DIRECTORY_SEPARATOR . $tool . $extension)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function filePresent(string $path): bool
    {
        return is_file($path) && is_executable($path);
    }

    /**
     * @return list<string>
     */
    private function extensions(): array
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return [''];
        }
        $pathext = getenv('PATHEXT');
        if (!is_string($pathext) || $pathext === '') {
            return ['.exe', '.bat', '.cmd', '.com'];
        }
        $extensions = [];
        foreach (explode(PATH_SEPARATOR, $pathext) as $extension) {
            if ($extension !== '') {
                $extensions[] = $extension;
            }
        }

        return $extensions;
    }

    /**
     * @param resource $stdin
     */
    private function copyInput($stdin, ?string $stdinFile): void
    {
        if ($stdinFile === null) {
            fclose($stdin);

            return;
        }
        $input = fopen($stdinFile, 'rb');
        if ($input === false) {
            fclose($stdin);
            throw new BackupException('The backup could not be read.');
        }
        stream_copy_to_stream($input, $stdin);
        fclose($input);
        fclose($stdin);
    }

    /**
     * @param resource $stdout
     */
    private function copyOutput($stdout, ?string $stdoutFile): void
    {
        if ($stdoutFile === null) {
            stream_get_contents($stdout);
            fclose($stdout);

            return;
        }
        $output = fopen($stdoutFile, 'wb');
        if ($output === false) {
            fclose($stdout);
            throw new BackupException('The backup could not be written.');
        }
        stream_copy_to_stream($stdout, $output);
        fclose($output);
        fclose($stdout);
    }

    /**
     * @param resource $stderr
     */
    private function readError($stderr): string
    {
        $errorText = stream_get_contents($stderr);
        fclose($stderr);

        return is_string($errorText) ? $errorText : '';
    }

    /**
     * @param list<string> $command
     */
    private function requireSuccess(array $command, int $exitCode, string $detail, string $redact): void
    {
        if ($exitCode === 0) {
            return;
        }
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

    /**
     * @param array{process: resource, stdin: resource, stdout: resource, stderr: resource} $started
     */
    private function close(array $started): void
    {
        foreach (['stdin', 'stdout', 'stderr'] as $pipe) {
            if (is_resource($started[$pipe])) {
                fclose($started[$pipe]);
            }
        }
        if (is_resource($started['process'])) {
            proc_close($started['process']);
        }
    }
}
