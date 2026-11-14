<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Http;

use ErrorException;
use PerfectConsign\Shared\Mail\PlatformOperatorNotice;
use Psr\Log\LoggerInterface;

final class ErrorHandling
{
    private static bool $handling = false;

    public static function applyDisplayErrors(bool $debug): void
    {
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('display_startup_errors', $debug ? '1' : '0');
    }

    public static function register(LoggerInterface $logger, ?PlatformOperatorNotice $notices = null): void
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

        $shutdown = static function () use ($logger, $notices): void {
            self::reportLastError(error_get_last(), $logger, $notices, 0);
        };
        self::$shutdown = $shutdown;
        register_shutdown_function($shutdown);
    }

    /**
     * @param array<string, mixed>|null $error
     */
    public static function reportLastError(
        ?array $error,
        LoggerInterface $logger,
        ?PlatformOperatorNotice $notices,
        int $bufferFloor,
    ): void {
        if ($error === null) {
            return;
        }
        $type = $error['type'] ?? null;
        if (!is_int($type) || !in_array($type, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }
        $context = [
            'file' => $error['file'] ?? '',
            'line' => $error['line'] ?? 0,
            'type' => $type,
            'message' => $error['message'] ?? '',
        ];
        $message = $context['message'];
        $logger->error(is_string($message) ? $message : '', $context);
        $notices?->serverFailed('fatal error', $context);
        while (ob_get_level() > $bufferFloor) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo 'The page could not be shown.';
    }

    public static ?\Closure $shutdown = null;
}
