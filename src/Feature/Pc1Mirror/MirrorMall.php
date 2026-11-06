<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pc1Mirror;

use PDO;
use PerfectConsign\Feature\Party\AssignmentBook;
use PerfectConsign\Feature\Party\PartyStore;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Database\Statements;

/**
 * Open disposable mirror mall for load/prove work.
 */
final class MirrorMall
{
    public function __construct(
        public readonly PDO $pdo,
        public readonly string $schema,
        public readonly Statements $statements,
        public readonly LedgerStore $ledger,
        public readonly PartyStore $parties,
        public readonly AssignmentBook $assignments,
    ) {
    }

    public static function provision(PDO $pdo, string $sqlDirectory): self
    {
        return MirrorMallFactory::provision($pdo, $sqlDirectory);
    }

    public static function openExisting(PDO $pdo): self
    {
        return MirrorMallFactory::openExisting($pdo);
    }
}
