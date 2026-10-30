<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Support;

use RuntimeException;

final class ProbeServer
{
    private const PORT = 8097;

    /** @var resource|null */
    private $process = null;

    private function __construct(
        private readonly string $logFile,
        private readonly int $port,
    ) {
    }

    public static function start(string $root): self
    {
        return self::open($root, self::PORT, true);
    }

    public static function startWithoutMailKey(string $root): self
    {
        return self::open($root, 8123, false);
    }

    public static function startIsolated(string $root): self
    {
        return self::open($root, 8098, true);
    }

    public static function startOn(string $root, int $port): self
    {
        return self::open($root, $port, true);
    }

    private static function open(string $root, int $port, bool $mailKey): self
    {
        self::assertPortFree($port);
        $logFile = tempnam(sys_get_temp_dir(), 'pc-http-');
        if ($logFile === false) {
            throw new RuntimeException('the server log could not be created');
        }
        $server = new self($logFile, $port);
        $public = $root . DIRECTORY_SEPARATOR . 'public';
        $script = $public . DIRECTORY_SEPARATOR . 'index.php';
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $public, $script],
            [
                0 => ['pipe', 'r'],
                1 => ['file', self::nullDevice(), 'w'],
                2 => ['file', $logFile, 'w'],
            ],
            $pipes,
            $root,
            self::environment($port, $mailKey),
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new RuntimeException('the probe server did not start');
        }
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        $server->process = $process;
        register_shutdown_function(static function () use ($server): void {
            $server->stop();
        });
        $server->waitUntilListening();

        return $server;
    }

    private static function assertPortFree(int $port): void
    {
        $errno = 0;
        $errstr = '';
        $socket = false;
        set_error_handler(static fn (): bool => true);
        try {
            $socket = stream_socket_client(
                'tcp://127.0.0.1:' . $port,
                $errno,
                $errstr,
                0.25
            );
        } finally {
            restore_error_handler();
        }
        if (!is_resource($socket)) {
            return;
        }
        fclose($socket);
        throw new RuntimeException(
            'probe port ' . $port . ' is already in use; stop the leftover php -S before HTTP tests'
        );
    }

    public function baseUrl(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    public static function mailFile(): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc-probe-mail.txt';
    }

    public function stop(): void
    {
        if (!is_resource($this->process)) {
            return;
        }
        $status = proc_get_status($this->process);
        $pid = $status['pid'] ?? 0;
        if (($status['running'] ?? false) === true && is_int($pid) && $pid > 0) {
            $this->kill($pid);
        }
        proc_close($this->process);
        $this->process = null;
    }

    /**
     * @return array<string, string>
     */
    private static function environment(int $port, bool $mailKey): array
    {
        $mail = self::mailFile();
        $created = fopen($mail, 'ab');
        if ($created === false) {
            throw new RuntimeException('the mail file could not be created');
        }
        fclose($created);
        $inherited = getenv();
        $env = [];
        if (is_array($inherited)) {
            foreach ($inherited as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $env[$key] = $value;
                }
            }
        }
        $env['STALL_MAIL_FILE'] = $mail;
        $env['STALL_PUBLIC_ORIGIN'] = 'http://127.0.0.1:' . $port;
        $coverage = $env['PC_COVERAGE'] ?? '';
        if (is_string($coverage) && $coverage !== '') {
            // Unit tests may putenv(APP_ENV) before the first WebTestCase boots the
            // probe. Coverage dumps only start when APP_ENV is test|coverage.
            $env['APP_ENV'] = 'coverage';
        } else {
            $env['APP_ENV'] = $env['APP_ENV'] ?? 'local';
        }
        if (!$mailKey) {
            $env['STALL_MAIL_KEY'] = 'missing';
        }

        return $env;
    }

    private function waitUntilListening(): void
    {
        $deadline = microtime(true) + 30;
        while (microtime(true) < $deadline) {
            $log = $this->log();
            if (str_contains($log, 'Failed to listen')) {
                throw new RuntimeException('the probe server failed to bind. ' . $log);
            }
            if (!$this->running()) {
                throw new RuntimeException('the probe server stopped. ' . $log);
            }
            $page = $this->ping();
            if ($page !== null && $page->status < 500) {
                if (!$this->running()) {
                    throw new RuntimeException('the probe server stopped. ' . $this->log());
                }
                if (str_contains($this->log(), 'Failed to listen')) {
                    throw new RuntimeException('the probe server failed to bind. ' . $this->log());
                }

                return;
            }
            if ($page !== null && $page->status >= 500) {
                throw new RuntimeException('the probe server failed: ' . $page->body . ' ' . $this->log());
            }
            usleep(200000);
        }
        throw new RuntimeException('the probe server did not accept a connection. ' . $this->log());
    }

    private function running(): bool
    {
        if (!is_resource($this->process)) {
            return false;
        }
        $status = proc_get_status($this->process);

        return ($status['running'] ?? false) === true;
    }

    private function ping(): ?PageResponse
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('the curl extension is required');
        }
        $curl = curl_init($this->baseUrl() . '/');
        if ($curl === false) {
            return null;
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 5000,
            CURLOPT_CONNECTTIMEOUT_MS => 1000,
        ]);
        $body = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        unset($curl);
        if (!is_string($body) || !is_int($status) || $status === 0) {
            return null;
        }

        return new PageResponse($status, $body, '');
    }

    private function log(): string
    {
        if (!is_file($this->logFile)) {
            return '';
        }
        $text = file_get_contents($this->logFile);

        return is_string($text) ? trim($text) : '';
    }

    private function kill(int $pid): void
    {
        $command = PHP_OS_FAMILY === 'Windows'
            ? 'taskkill /F /T /PID ' . $pid
            : 'kill -TERM ' . $pid;
        $pipes = [];
        $process = proc_open($command, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            return;
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);
    }

    private static function nullDevice(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    }
}
