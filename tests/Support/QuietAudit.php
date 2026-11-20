<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Support;

use PDO;
use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\AuditLog;
use PerfectConsign\Bootstrap\JournalAudit;
use PerfectConsign\Feature\Admin\AdminAudit;
use PerfectConsign\Feature\Admin\AdminSession;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\RequestId;

final class QuietAudit
{
    public static function log(Statements $statements): AuditLog
    {
        return new AuditLog(
            $statements,
            new Request('POST', '/', [], [], '', '', '127.0.0.1'),
            new RequestId('test-request'),
        );
    }

    public static function session(): Session
    {
        $session = new Session(null, false);
        $session->set('actor_role', 'owner');
        $session->set('actor_username', 'ada');
        $session->set('actor_account', '01900000-0000-7000-8000-0000000000aa');
        $session->set('owner_name', 'ada');

        return $session;
    }

    public static function make(Statements $statements, ?Session $session = null): JournalAudit
    {
        $session ??= new Session(null, false);

        return new JournalAudit(new Actor($session), self::log($statements), new AdminSession($session), $statements);
    }

    public static function owner(Statements $statements): JournalAudit
    {
        return self::make($statements, self::session());
    }

    public static function journal(Statements $statements, Actor $actor, ?Session $session = null): JournalAudit
    {
        $session ??= new Session(null, false);

        return new JournalAudit($actor, self::log($statements), new AdminSession($session), $statements);
    }

    public static function admin(PDO $pdo, Request $request): AdminAudit
    {
        return new AdminAudit($pdo, $request, new RequestId('test-request'));
    }
}
