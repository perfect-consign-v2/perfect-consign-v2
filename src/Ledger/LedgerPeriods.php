<?php

declare(strict_types=1);

namespace PerfectConsign\Ledger;

use DateTimeImmutable;
use PDO;

final class LedgerPeriods
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function idFor(string $bookId, string $postingDate): string
    {
        $open = $this->covering($bookId, $postingDate, 'open');
        if ($open !== null) {
            return $open;
        }
        if ($this->covering($bookId, $postingDate, 'closed') !== null) {
            throw new DatabaseRefusal('That day is already closed.');
        }
        $this->openYear($bookId, $postingDate);
        $open = $this->covering($bookId, $postingDate, 'open');
        if ($open === null) {
            throw new DatabaseRefusal('That day could not be opened.');
        }

        return $open;
    }

    private function covering(string $bookId, string $postingDate, string $status): ?string
    {
        $period = $this->pdo->prepare(
            'SELECT id FROM accounting_periods
             WHERE book_id = ?::uuid AND status = ? AND period_range @> ?::date'
        );
        $period->execute([$bookId, $status, $postingDate]);
        $periodId = $period->fetchColumn();
        if (!is_string($periodId) || $periodId === '') {
            return null;
        }

        return $periodId;
    }

    private function openYear(string $bookId, string $postingDate): void
    {
        $year = substr($postingDate, 0, 4);
        $start = $year . '-01-01';
        $end = $year . '-12-31';
        $previousEnd = $this->nearestDate(
            'SELECT ends_on FROM accounting_periods
             WHERE book_id = ?::uuid AND ends_on < ?::date
             ORDER BY ends_on DESC LIMIT 1',
            $bookId,
            $postingDate
        );
        if ($previousEnd !== null) {
            $dayAfter = (new DateTimeImmutable($previousEnd))->modify('+1 day')->format('Y-m-d');
            if ($dayAfter > $start) {
                $start = $dayAfter;
            }
        }
        $nextStart = $this->nearestDate(
            'SELECT starts_on FROM accounting_periods
             WHERE book_id = ?::uuid AND starts_on > ?::date
             ORDER BY starts_on LIMIT 1',
            $bookId,
            $postingDate
        );
        if ($nextStart !== null) {
            $dayBefore = (new DateTimeImmutable($nextStart))->modify('-1 day')->format('Y-m-d');
            if ($dayBefore < $end) {
                $end = $dayBefore;
            }
        }
        if ($start > $postingDate || $end < $postingDate) {
            throw new DatabaseRefusal('That day could not be opened.');
        }
        $insert = $this->pdo->prepare(
            'INSERT INTO accounting_periods (book_id, starts_on, ends_on, status) VALUES (?::uuid, ?::date, ?::date, ?)'
        );
        $insert->execute([$bookId, $start, $end, 'open']);
    }

    private function nearestDate(string $sql, string $bookId, string $postingDate): ?string
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute([$bookId, $postingDate]);
        $date = $statement->fetchColumn();
        if (!is_string($date) || $date === '') {
            return null;
        }

        return $date;
    }
}
