<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Http;

final class JsonResponse
{
    /**
     * @param array<string, mixed>|list<mixed> $payload
     */
    public static function send(array $payload, int $status, string $failure): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        try {
            echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            http_response_code(500);
            echo json_encode(['error' => $failure], JSON_THROW_ON_ERROR);
        }
        throw new ResponseSent('json');
    }
}
