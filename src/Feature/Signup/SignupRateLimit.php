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
        return !$this->claim($clientAddress, false);
    }

    public function remember(string $clientAddress): void
    {
        $this->claim($clientAddress, true);
    }

    private function claim(string $clientAddress, bool $insert): bool
    {
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT pg_advisory_xact_lock(87231, hashtext(?))');
            $lock->execute([$clientAddress]);
            $prune = $this->pdo->prepare(
                'DELETE FROM platform.signup_attempts
                 WHERE attempted_at <= clock_timestamp() - make_interval(secs => ?)'
            );
            $prune->execute([$this->windowSeconds()]);
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*)::int
                 FROM platform.signup_attempts
                 WHERE client_address = ?
                   AND attempted_at > clock_timestamp() - make_interval(secs => ?)'
            );
            $statement->execute([$clientAddress, $this->windowSeconds()]);
            $count = RowValue::integer($statement->fetchColumn());
            if ($count >= $this->maxAttempts()) {
                $this->pdo->commit();

                return false;
            }
            if ($insert) {
                $insertRow = $this->pdo->prepare(
                    'INSERT INTO platform.signup_attempts (client_address) VALUES (?)'
                );
                $insertRow->execute([$clientAddress]);
            }
            $this->pdo->commit();

            return true;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
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
