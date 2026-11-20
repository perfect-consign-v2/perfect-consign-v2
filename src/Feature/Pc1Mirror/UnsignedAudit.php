<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pc1Mirror;

use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\AuditLog;
use PerfectConsign\Bootstrap\JournalAudit;
use PerfectConsign\Feature\Admin\AdminSession;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Shared\Http\RequestId;

final class UnsignedAudit
{
    public static function journal(Statements $statements): JournalAudit
    {
        $session = new Session(null, false);

        return new JournalAudit(
            new Actor($session),
            new AuditLog($statements, new Request('POST', '/', [], []), new RequestId('store-load')),
            new AdminSession($session),
            $statements,
        );
    }
}
