<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Http;

final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly string $referer = '',
    ) {
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
