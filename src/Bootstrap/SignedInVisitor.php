<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PerfectApp\Session\Session;
use PerfectConsign\Feature\Admin\AdminSession;
use PerfectConsign\Shared\Http\Request;

/**
 * Someone signed in is never counted toward a probe ban and is never refused by one.
 * A request without the session cookie is a visitor, and no session is started for it.
 */
final class SignedInVisitor
{
    /**
     * @param array<mixed> $cookies
     */
    public static function hasCookie(array $cookies): bool
    {
        $name = session_name();
        if (!is_string($name)) {
            return false;
        }
        $value = $cookies[$name] ?? null;

        return is_string($value) && $value !== '';
    }

    public static function present(Session $session, Request $request): bool
    {
        if ((new Actor($session))->slug() !== null) {
            return true;
        }
        $admins = new AdminSession($session);
        if ($admins->current() !== null && !$admins->stale(time())) {
            return true;
        }

        return self::consignor($session, $request->path);
    }

    private static function consignor(Session $session, string $path): bool
    {
        if (preg_match('#\A/([a-z][a-z0-9-]{0,62})/consignor(?:/|\z)#', $path, $match) !== 1) {
            return false;
        }
        $vendor = $session->get('consignor:' . $match[1]);

        return is_string($vendor) && preg_match('/^[0-9a-fA-F-]{36}$/', $vendor) === 1;
    }
}
