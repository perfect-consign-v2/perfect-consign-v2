<?php

declare(strict_types=1);

namespace PerfectConsign\Ledger;

use PDO;
use PDOException;

/**
 * Posts through the database's posting functions, which work out their own
 * amounts and refuse what the rules do not allow. PHP opens the period, calls
 * the function, links the drawer and check in the same transaction, then has
 * every deferred rule checked before the commit.
 *
 * Open for a test double. Production code constructs this class; it does not extend it.
 */
class PostingFunctions
{
    private const CALL = '/^stall_[a-z_]+\((\?(::[a-z]+)?(, )?)*\)$/';

    public function __construct(
        private readonly PDO $pdo,
        private readonly LedgerPeriods $periods,
        private readonly JournalCash $cash,
    ) {
    }

    /**
     * Posts one journal and returns its id. The call is a fixed SQL expression
     * from the caller, never user text; every value a person chose is bound.
     *
     * @param list<string|int|null> $values
     * @param (callable(string): void)|null $beforeCommit
     */
    public function post(
        string $postingDate,
        string $call,
        array $values,
        ?string $sessionId = null,
        ?string $checkNumber = null,
        ?int $tenderedMinor = null,
        ?callable $beforeCommit = null,
    ): string {
        $journalId = $this->run([$postingDate], $call, $values, function (mixed $posted) use ($sessionId, $checkNumber, $tenderedMinor, $beforeCommit): void {
            if (!is_string($posted) || $posted === '') {
                throw new DatabaseRefusal('the posting function returned no journal');
            }
            $this->cash->linkDrawer($posted, $sessionId, $checkNumber);
            $this->cash->storeTendered($posted, $tenderedMinor);
            if ($beforeCommit !== null) {
                $beforeCommit($posted);
            }
        });

        return is_string($journalId) ? $journalId : '';
    }

    /**
     * Runs a posting function that may post several journals (late fees), each
     * on its own date, and returns how many it posted.
     *
     * @param list<string> $postingDates
     * @param list<string|int|null> $values
     */
    public function postBatch(array $postingDates, string $call, array $values): int
    {
        $posted = $this->run($postingDates, $call, $values, null);

        return is_int($posted) ? $posted : (int) (is_string($posted) ? $posted : 0);
    }

    /**
     * @param list<string> $postingDates
     * @param list<string|int|null> $values
     * @param (callable(mixed): void)|null $linked
     */
    private function run(array $postingDates, string $call, array $values, ?callable $linked): mixed
    {
        if (preg_match(self::CALL, $call) !== 1) {
            throw new DatabaseRefusal('that posting function is not one the books use');
        }
        $own = !$this->pdo->inTransaction();
        try {
            if ($own) {
                $this->pdo->beginTransaction();
            }
            $book = $this->periods->defaultBookId();
            foreach (array_unique($postingDates) as $postingDate) {
                $this->periods->idFor($book, $postingDate);
            }
            $statement = $this->pdo->prepare('SELECT ' . $call . ' AS posted');
            $statement->execute($values);
            $posted = $statement->fetchColumn();
            if ($linked !== null) {
                $linked($posted);
            }
            $this->pdo->exec('SET CONSTRAINTS ALL IMMEDIATE');
            if ($own) {
                $this->pdo->commit();
            }

            return $posted;
        } catch (PDOException $error) {
            $this->abort($own);

            throw DatabaseRefusal::fromPdo($error);
        } catch (DatabaseRefusal $error) {
            $this->abort($own);

            throw $error;
        }
    }

    private function abort(bool $own): void
    {
        if ($own && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
