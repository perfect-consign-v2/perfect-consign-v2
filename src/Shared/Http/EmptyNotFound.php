<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Http;

final class EmptyNotFound
{
    public static function send(): void
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Length: 0');
        header('Connection: close');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }
}
