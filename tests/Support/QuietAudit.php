<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Support;

use PerfectApp\Session\Session;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\AuditLog;
use PerfectConsign\Bootstrap\JournalAudit;
use PerfectConsign\Shared\Database\Statements;

final class QuietAudit
{
    public static function make(Statements $statements): JournalAudit
    {
        return new JournalAudit(new Actor(new Session(null, false)), new AuditLog($statements));
    }
}
