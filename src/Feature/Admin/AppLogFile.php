<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

use RuntimeException;

final class AppLogFile
{
    public const TAIL_BYTES = 200_000;

    public function path(): string
    {
        return dirname(__DIR__, 3)
            . DIRECTORY_SEPARATOR
            . 'var'
            . DIRECTORY_SEPARATOR
            . 'log'
            . DIRECTORY_SEPARATOR
            . 'app.log';
    }

    public function relativePath(): string
    {
        return 'var/log/app.log';
    }

    public function byteCount(): int
    {
        $path = $this->path();
        if (!is_file($path)) {
            return 0;
        }
        $size = filesize($path);
        if ($size === false) {
            throw new RuntimeException('The application log size could not be read.');
        }

        return $size;
    }

    /**
     * @return array{
     *     path: string,
     *     exists: bool,
     *     bytes: int,
     *     modified_at: string,
     *     truncated: bool,
     *     body: string
     * }
     */
    public function snapshot(int $maxBytes = self::TAIL_BYTES): array
    {
        if ($maxBytes < 1) {
            throw new RuntimeException('Log tail size must be at least 1 byte.');
        }
        $path = $this->path();
        if (!is_file($path)) {
            return [
                'path' => $this->relativePath(),
                'exists' => false,
                'bytes' => 0,
                'modified_at' => '',
                'truncated' => false,
                'body' => '',
            ];
        }
        $bytes = $this->byteCount();
        $modified = filemtime($path);
        if ($modified === false) {
            throw new RuntimeException('The application log time could not be read.');
        }
        if ($bytes === 0) {
            return [
                'path' => $this->relativePath(),
                'exists' => true,
                'bytes' => 0,
                'modified_at' => gmdate('Y-m-d H:i:s', $modified) . ' UTC',
                'truncated' => false,
                'body' => '',
            ];
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The application log could not be opened.');
        }
        try {
            $truncated = $bytes > $maxBytes;
            if ($truncated && fseek($handle, -$maxBytes, SEEK_END) !== 0) {
                throw new RuntimeException('The application log could not be read.');
            }
            $body = stream_get_contents($handle);
            if ($body === false) {
                throw new RuntimeException('The application log could not be read.');
            }
        } finally {
            fclose($handle);
        }
        if ($truncated) {
            $newline = strpos($body, "\n");
            if ($newline !== false) {
                $body = substr($body, $newline + 1);
            }
        }

        return [
            'path' => $this->relativePath(),
            'exists' => true,
            'bytes' => $bytes,
            'modified_at' => gmdate('Y-m-d H:i:s', $modified) . ' UTC',
            'truncated' => $truncated,
            'body' => $body,
        ];
    }

    public function clear(): void
    {
        $path = $this->path();
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('The log directory could not be created.');
        }
        $written = file_put_contents($path, '', LOCK_EX);
        if ($written === false) {
            throw new RuntimeException('The application log could not be cleared.');
        }
    }
}
