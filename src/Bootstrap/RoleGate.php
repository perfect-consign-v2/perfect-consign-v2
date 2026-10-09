<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

final class RoleGate
{
    public static function allows(string $role, string $rest, string $method): bool
    {
        if ($role === 'owner') {
            return true;
        }
        if ($role === 'cashier') {
            return self::cashier($rest, $method);
        }
        if ($role === 'vendor') {
            return self::vendor($rest);
        }

        return false;
    }

    private static function cashier(string $rest, string $method): bool
    {
        if (self::counter($rest) || self::rentPage($rest)) {
            return true;
        }

        return self::drawer($rest, $method);
    }

    private static function counter(string $rest): bool
    {
        if (in_array($rest, ['/dashboard', '/logout', '/logout/confirm'], true)) {
            return true;
        }

        return $rest === '/pos' || str_starts_with($rest, '/pos/');
    }

    private static function rentPage(string $rest): bool
    {
        if ($rest === '/rent' || $rest === '/rent/collect' || $rest === '/rent/payment') {
            return true;
        }

        return str_starts_with($rest, '/rent/receipt/');
    }

    private static function drawer(string $rest, string $method): bool
    {
        if (!str_starts_with($rest, '/registers')) {
            return false;
        }

        return !self::lockedRegister($rest, $method);
    }

    private static function lockedRegister(string $rest, string $method): bool
    {
        if ($rest === '/registers/new') {
            return true;
        }
        if (preg_match('#^/registers/[0-9a-fA-F-]{36}/(edit|delete)$#', $rest) === 1) {
            return true;
        }
        if ($method !== 'POST') {
            return false;
        }
        if ($rest === '/registers') {
            return true;
        }

        return preg_match('#^/registers/[0-9a-fA-F-]{36}$#', $rest) === 1;
    }

    private static function vendor(string $rest): bool
    {
        return in_array($rest, ['/vendor', '/vendor/labels', '/logout', '/logout/confirm'], true);
    }
}
