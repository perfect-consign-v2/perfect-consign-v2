<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Signup;

use PDO;
use PerfectConsign\Shared\Scalar\RowValue;

final class SignupRateLimit
{
    private const WINDOW_SECONDS = 3600;

    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function refuse(string $clientAddress): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*)::int
             FROM platform.signup_attempts
             WHERE client_address = ?
               AND attempted_at > clock_timestamp() - make_interval(secs => ?)'
        );
        $statement->execute([$clientAddress, $this->windowSeconds()]);

        return RowValue::integer($statement->fetchColumn()) >= $this->maxAttempts();
    }

    public function remember(string $clientAddress): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO platform.signup_attempts (client_address) VALUES (?)'
        );
        $statement->execute([$clientAddress]);
    }

    private function maxAttempts(): int
    {
        $raw = getenv('STALL_SIGNUP_MAX_ATTEMPTS');
        if (is_string($raw) && ctype_digit($raw) && (int) $raw > 0) {
            return (int) $raw;
        }

        return self::MAX_ATTEMPTS;
    }

    private function windowSeconds(): int
    {
        $raw = getenv('STALL_SIGNUP_WINDOW_SECONDS');
        if (is_string($raw) && ctype_digit($raw) && (int) $raw > 0) {
            return (int) $raw;
        }

        return self::WINDOW_SECONDS;
    }
}
