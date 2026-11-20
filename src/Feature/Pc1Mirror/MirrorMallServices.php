<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pc1Mirror;

use PDO;
use PerfectConsign\Feature\Party\AssignmentBook;
use PerfectConsign\Feature\Party\PartyStore;
use PerfectConsign\Ledger\JournalCash;
use PerfectConsign\Ledger\LedgerPeriods;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Database\Statements;

final class MirrorMallServices
{
    public static function open(PDO $pdo, string $schema): MirrorMall
    {
        $pdo->exec('SET search_path TO ' . $schema . ', pg_catalog, extensions');
        $statements = new Statements($pdo);
        $ledger = new LedgerStore($pdo, $statements, new LedgerPeriods($pdo), new JournalCash($pdo));
        $assignments = new AssignmentBook($statements);

        return new MirrorMall(
            $pdo,
            $schema,
            $statements,
            $ledger,
            new PartyStore($statements, UnsignedAudit::journal($statements), $assignments),
            $assignments,
        );
    }
}
