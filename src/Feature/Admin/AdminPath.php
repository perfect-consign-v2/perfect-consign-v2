<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

use RuntimeException;

/**
 * The platform door is one unlisted path segment from STALL_ADMIN_PATH.
 * /admin is never this prefix.
 */
final class AdminPath
{
    private const PATTERN = '#\A/[a-z0-9][a-z0-9-]{15,62}\z#';

    public function __construct(private readonly string $prefix)
    {
        if (in_array($this->segment($prefix), ['', 'admin'], true) || preg_match(self::PATTERN, $prefix) !== 1) {
            throw new RuntimeException('STALL_ADMIN_PATH is not a private admin path');
        }
    }

    public static function fromEnv(): self
    {
        $value = getenv('STALL_ADMIN_PATH');
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('STALL_ADMIN_PATH is not set');
        }

        return new self($value);
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function url(string $suffix): string
    {
        if ($suffix === '' || $suffix === '/') {
            return $this->prefix;
        }
        if (!str_starts_with($suffix, '/')) {
            throw new RuntimeException('admin link is not a path');
        }

        return $this->prefix . $suffix;
    }

    public static function isPublicAdmin(string $path): bool
    {
        return $path === '/admin' || str_starts_with($path, '/admin/');
    }

    private function segment(string $prefix): string
    {
        if (!str_starts_with($prefix, '/')) {
            return '';
        }

        return substr($prefix, 1);
    }
}
