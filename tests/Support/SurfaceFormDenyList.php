<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Support;

/**
 * POST actions the surface form crawler must not submit.
 */
final class SurfaceFormDenyList
{
    public static function blocks(string $action, string $slug): bool
    {
        $path = explode('?', $action, 2)[0];
        $prefix = '/' . $slug;
        if (!str_starts_with($path, $prefix)) {
            return true;
        }
        $rest = substr($path, strlen($prefix));
        if ($rest === false || $rest === '') {
            return true;
        }
        if (str_ends_with($rest, '/delete')) {
            return true;
        }
        if (in_array($rest, [
            '/logout',
            '/logout/confirm',
            '/consignor/logout',
            '/password',
            '/password/email',
            '/payout',
            '/payout/undo',
            '/payouts/undo',
            '/payouts/close',
            '/pos/pay',
            '/rent/charge',
            '/close',
            '/backup',
            '/reset-test-data',
        ], true)) {
            return true;
        }
        if (preg_match('#^/pos/receipt/[0-9a-fA-F-]{36}/void$#', $rest) === 1) {
            return true;
        }
        if (preg_match('#^/journals/[0-9a-fA-F-]{36}/void$#', $rest) === 1) {
            return true;
        }
        if (preg_match('#^/registers/[0-9a-fA-F-]{36}/(open|close|drop)$#', $rest) === 1) {
            return true;
        }
        if (preg_match('#^/staff/[0-9a-fA-F-]{36}/(password|books-password)$#', $rest) === 1) {
            return true;
        }
        if (str_starts_with($rest, '/admin')) {
            return true;
        }

        return false;
    }
}
