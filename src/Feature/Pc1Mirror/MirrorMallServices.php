<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pc1Mirror;

use PDO;
use PerfectConsign\Bootstrap\Actor;
use PerfectConsign\Bootstrap\AuditLog;
use PerfectConsign\Bootstrap\JournalAudit;
use PerfectConsign\Feature\Party\AssignmentBook;
use PerfectConsign\Feature\Party\PartyStore;
use PerfectConsign\Ledger\JournalCash;
use PerfectConsign\Ledger\LedgerPeriods;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Database\Statements;
use PerfectApp\Session\Session;

final class MirrorMallServices
{
    public static function open(PDO $pdo, string $schema): MirrorMall
    {
        $pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');
        $statements = new Statements($pdo);
        $ledger = new LedgerStore($pdo, $statements, new LedgerPeriods($pdo), new JournalCash($pdo));
        $assignments = new AssignmentBook($statements);
        $audit = new JournalAudit(new Actor(new Session(null, false)), new AuditLog($statements));

        return new MirrorMall(
            $pdo,
            $schema,
            $statements,
            $ledger,
            new PartyStore($statements, $audit, $assignments),
            $assignments,
        );
    }
}
