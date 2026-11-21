<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Report;

use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Shared\Database\Statements;

final class ReportTie
{
    public function __construct(private readonly Statements $statements)
    {
    }

    public function tax(string $from, string $to): void
    {
        $this->call(
            'SELECT stall_refuse_tax_drift(?::date, ?::date)',
            [$from, $to],
            'Sales tax does not match the books.',
        );
    }

    public function rent(): void
    {
        $this->call('SELECT stall_refuse_rent_roll()', [], 'The rent roll does not match the books.');
    }

    public function stored(): void
    {
        $this->call('SELECT stall_refuse_stored_value()', [], 'Stored value does not match the books.');
    }

    /**
     * @param list<mixed> $params
     */
    private function call(string $sql, array $params, string $sentence): void
    {
        try {
            $this->statements->bound($sql, $params);
        } catch (DatabaseRefusal $error) {
            if ($error->sqlState() !== '23514') {
                throw $error;
            }
            throw new DatabaseRefusal($sentence, 0, $error, '23514');
        }
    }
}
