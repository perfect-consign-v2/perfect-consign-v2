<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Http;

use ErrorException;
use Psr\Log\LoggerInterface;

final class ErrorHandling
{
    private static bool $handling = false;

    public static function register(LoggerInterface $logger): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line) use ($logger): bool {
            if ((error_reporting() & $severity) === 0 || self::$handling) {
                return false;
            }
            self::$handling = true;
            $logger->error($message, ['file' => $file, 'line' => $line]);
            self::$handling = false;
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
    }
}
