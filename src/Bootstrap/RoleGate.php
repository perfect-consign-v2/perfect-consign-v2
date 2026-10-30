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
        if ($role === 'manager') {
            return self::manager($rest, $method);
        }
        if ($role === 'vendor') {
            return self::vendor($rest, $method);
        }
        if ($role === 'books') {
            return self::books($rest, $method);
        }

        return false;
    }

    private static function manager(string $rest, string $method): bool
    {
        if (self::cashier($rest, $method)) {
            return true;
        }
        if (self::floor($rest)) {
            return true;
        }
        if ($method !== 'GET') {
            return false;
        }

        return self::bookPage($rest) || self::reportPage($rest, 'manager');
    }

    private static function floor(string $rest): bool
    {
        if (in_array($rest, ['/parties', '/map', '/customers', '/wait-list', '/offers', '/letters', '/booths', '/inventory'], true)) {
            return true;
        }
        if (
            str_starts_with($rest, '/parties/')
            || str_starts_with($rest, '/vendors/')
            || str_starts_with($rest, '/inventory/')
            || str_starts_with($rest, '/booths/')
            || str_starts_with($rest, '/customers/')
            || str_starts_with($rest, '/wait-list/')
            || str_starts_with($rest, '/offers/')
            || str_starts_with($rest, '/letters/')
        ) {
            return true;
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
        if (in_array($rest, ['/dashboard', '/sales', '/logout', '/logout/confirm', '/password', '/password/email'], true)) {
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

    private static function vendor(string $rest, string $method): bool
    {
        if ($method === 'GET' && preg_match('#^/inventory/[0-9a-fA-F-]{36}/photo$#', $rest) === 1) {
            return true;
        }
        if (in_array($rest, ['/vendor', '/vendor/labels', '/vendor/sales', '/vendor/inventory', '/vendor/offers', '/logout', '/logout/confirm', '/password', '/password/email'], true)) {
            return true;
        }
        if (str_starts_with($rest, '/vendor/offers/')) {
            return true;
        }

        return preg_match('#^/vendor/checks/[0-9a-fA-F-]{36}$#', $rest) === 1;
    }

    private static function books(string $rest, string $method): bool
    {
        if ($method === 'POST') {
            return in_array($rest, ['/logout', '/logout/confirm', '/password', '/password/email'], true);
        }
        if ($method !== 'GET') {
            return false;
        }

        return self::bookPage($rest) || self::reportPage($rest, 'books');
    }

    private static function bookPage(string $rest): bool
    {
        if (in_array($rest, ['/sales', '/month', '/rent', '/payouts', '/journals', '/password', '/dashboard'], true)) {
            return true;
        }
        if (preg_match('#^/journals/[0-9a-fA-F-]{36}$#', $rest) === 1) {
            return true;
        }
        if (str_starts_with($rest, '/rent/receipt/')) {
            return true;
        }
        if (str_starts_with($rest, '/payouts/')) {
            return true;
        }

        return str_starts_with($rest, '/pos/receipt/');
    }

    private static function reportPage(string $rest, string $role): bool
    {
        if (!Permission::allows($role, 'report.read')) {
            return false;
        }
        if (in_array($rest, [
            '/reports',
            '/reports/sales-tax',
            '/reports/sales',
            '/reports/rent',
            '/reports/payouts',
            '/reports/stored-value',
            '/reports/expiring',
            '/reports/donated',
            '/reports/sell-through',
            '/reports/sales-tax.csv',
            '/reports/sales.csv',
            '/reports/rent.csv',
            '/reports/payouts.csv',
            '/reports/stored-value.csv',
            '/reports/expiring.csv',
            '/reports/donated.csv',
            '/reports/sell-through.csv',
            '/reports/build',
        ], true)) {
            return true;
        }

        return preg_match('#^/reports/saved/[0-9a-fA-F-]{36}$#', $rest) === 1;
    }
}
