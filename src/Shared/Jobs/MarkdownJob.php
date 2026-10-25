<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Jobs;

use PDO;
use RuntimeException;

final class MarkdownJob
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function propose(string $date): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new RuntimeException('a markdown needs a day');
        }
        $statement = $this->pdo->prepare('SELECT stall_propose_markdowns(?::date)');
        $statement->execute([$date]);
    }
}
