<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Http;

use PerfectConsign\Tests\Support\ProbeServer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProbeServerPortTest extends TestCase
{
    public function testStartRefusesWhenThePortIsAlreadyTaken(): void
    {
        $root = dirname(__DIR__, 2);
        $public = $root . DIRECTORY_SEPARATOR . 'public';
        $script = $public . DIRECTORY_SEPARATOR . 'index.php';
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $pipes = [];
        $stale = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:8099', '-t', $public, $script],
            [
                0 => ['pipe', 'r'],
                1 => ['file', $null, 'w'],
                2 => ['file', $null, 'w'],
            ],
            $pipes,
            $root,
            null,
            ['bypass_shell' => true]
        );
        self::assertIsResource($stale);
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        usleep(400000);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('probe port 8099 is already in use');
            ProbeServer::startOn($root, 8099);
        } finally {
            $status = proc_get_status($stale);
            $pid = $status['pid'] ?? 0;
            if (($status['running'] ?? false) === true && is_int($pid) && $pid > 0) {
                if (PHP_OS_FAMILY === 'Windows') {
                    proc_open(
                        'taskkill /F /T /PID ' . $pid,
                        [1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']],
                        $killPipes
                    );
                } else {
                    posix_kill($pid, SIGTERM);
                }
            }
            proc_close($stale);
        }
    }
}
