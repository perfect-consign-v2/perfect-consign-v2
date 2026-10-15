<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PDO;

final class DatabaseRole
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function reset(): void
    {
        if ($this->pdo->exec('RESET ROLE') === false) {
            throw new \RuntimeException('the database role could not be reset');
        }
    }

    public function assume(string $role): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role) !== 1) {
            throw new \RuntimeException('the mall role is not usable');
        }
        if ($this->pdo->exec('SET ROLE ' . $role) === false) {
            throw new \RuntimeException('the mall role could not be used');
        }
    }
}
