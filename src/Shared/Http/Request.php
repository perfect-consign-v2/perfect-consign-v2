<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Http;

final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, array{tmp_name: string, error: int, size: int, name: string}> $uploads
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly string $referer = '',
        public readonly string $origin = '',
        public readonly string $address = '',
        public readonly array $uploads = [],
        public readonly string $accept = '',
    ) {
    }

    public function wantsJson(): bool
    {
        return str_contains(strtolower($this->accept), 'application/json');
    }

    /**
     * @return array{tmp_name: string, error: int, size: int, name: string}|null
     */
    public function upload(string $name): ?array
    {
        $file = $this->uploads[$name] ?? null;
        if (!is_array($file)) {
            return null;
        }

        return $file;
    }

    public function field(string $name): string
    {
        $value = $this->post[$name] ?? '';
        if (!is_string($value)) {
            return '';
        }

        return trim($value);
    }

    public function query(string $name): string
    {
        $value = $this->query[$name] ?? '';
        if (!is_string($value)) {
            return '';
        }

        return trim($value);
    }

    public function raw(string $name): string
    {
        $value = $this->post[$name] ?? '';
        if (!is_string($value)) {
            return '';
        }

        return $value;
    }
}
