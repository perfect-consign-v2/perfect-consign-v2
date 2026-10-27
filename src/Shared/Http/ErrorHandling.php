<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Http;

use ErrorException;
use Psr\Log\LoggerInterface;

final class ErrorHandling
{
    private static bool $handling = false;

    public static function applyDisplayErrors(bool $debug): void
    {
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('display_startup_errors', $debug ? '1' : '0');
    }

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

        register_shutdown_function(static function () use ($logger): void {
            $error = error_get_last();
            if ($error === null) {
                return;
            }
            $type = $error['type'];
            if (!in_array($type, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }
            $logger->error($error['message'], [
                'file' => $error['file'],
                'line' => $error['line'],
                'type' => $type,
            ]);
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/html; charset=utf-8');
            }
            echo 'The page could not be shown.';
        });
    }
}
