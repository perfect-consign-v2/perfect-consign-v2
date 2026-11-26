<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Support;

use PDO;
use PerfectConsign\Bootstrap\SignInAlert;
use PerfectConsign\Bootstrap\SignInDevice;
use PerfectConsign\Bootstrap\SignInGate;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Mail\MailOutbox;
use Psr\Log\NullLogger;

/**
 * Builds the sign-in gate the way the container does, for tests that wire
 * the doors by hand. The browser has no device cookie unless one is given.
 */
final class SignInTestKit
{
    /**
     * @param array<string, string> $cookies
     */
    public static function gate(PDO $pdo, ?Request $request = null, array $cookies = []): SignInGate
    {
        return new SignInGate(
            $pdo,
            $request ?? new Request('POST', '/', [], [], '', '', '127.0.0.1'),
            new SignInDevice($cookies, false),
            new SignInAlert($pdo, new MailOutbox($pdo)),
            new NullLogger(),
        );
    }

    /**
     * A database where every sign-in attempt is open, for tests about what a
     * door does after the limits let the attempt through.
     */
    public static function openPdo(): ScriptPdo
    {
        $pdo = new ScriptPdo();
        $pdo->onPrepare('sign_in_begin', [['verdict' => 'open', 'trusted' => 'f', 'alerted' => 'f']]);

        return $pdo;
    }
}
