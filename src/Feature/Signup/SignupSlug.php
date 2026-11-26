<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Signup;

use PerfectConsign\Schema\TenantProvisioner;
use RuntimeException;

final class SignupSlug
{
    public static function fromName(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        if ($slug === '' || preg_match('/^[a-z]/', $slug) !== 1) {
            $slug = 's' . $slug;
        }
        if (strlen($slug) > 63) {
            $slug = substr($slug, 0, 63);
            $slug = rtrim($slug, '-');
        }

        return $slug;
    }

    public static function reserved(string $slug): bool
    {
        return in_array($slug, TenantProvisioner::RESERVED, true);
    }

    public static function username(string $email): string
    {
        $local = strstr($email, '@', true);
        if (!is_string($local) || $local === '') {
            throw new RuntimeException('Enter a valid email address.');
        }
        $base = strtolower($local);
        $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? '';
        $base = trim($base, '-');
        if ($base === '' || preg_match('/^[a-z]/', $base) !== 1) {
            $base = 'u' . $base;
        }
        if (strlen($base) > 63) {
            $base = substr($base, 0, 63);
            $base = rtrim($base, '-');
        }

        return $base;
    }
}
