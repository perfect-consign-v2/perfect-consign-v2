<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Http;

use PDO;
use RuntimeException;

final class ProbeBanStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function banned(string $address): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM platform.probe_bans WHERE client_address = ?'
        );
        $statement->execute([$address]);

        return $statement->fetchColumn() !== false;
    }

    public function insertBan(string $address, string $reason, string $agent, string $path): bool
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO platform.probe_bans (client_address, reason, user_agent, path)
             VALUES (?, ?, ?, ?)
             ON CONFLICT (client_address) DO NOTHING
             RETURNING client_address'
        );
        $statement->execute([
            $address,
            $reason,
            self::clip($agent, 200),
            self::clip($path, 200),
        ]);
        $inserted = $statement->fetchColumn();
        if (!is_string($inserted) || $inserted === '') {
            return false;
        }
        $this->clearStreak($address);

        return true;
    }

    public function increment(string $address): int
    {
        $this->pruneStreaks();
        $statement = $this->pdo->prepare(
            'INSERT INTO platform.probe_streaks (client_address, streak)
             VALUES (?, 1)
             ON CONFLICT (client_address) DO UPDATE
             SET streak = platform.probe_streaks.streak + 1,
                 updated_at = clock_timestamp()
             RETURNING streak'
        );
        $statement->execute([$address]);
        $streak = $statement->fetchColumn();
        if (is_int($streak)) {
            return $streak;
        }
        if (is_string($streak) && ctype_digit($streak)) {
            return (int) $streak;
        }

        throw new RuntimeException('the probe streak was not stored');
    }

    public function clearStreak(string $address): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM platform.probe_streaks WHERE client_address = ?'
        );
        $statement->execute([$address]);
    }

    /**
     * @return list<array{address: string, banned_at: string, reason: string, path: string}>
     */
    public function listBans(): array
    {
        $statement = $this->pdo->query(
            'SELECT client_address AS address, banned_at::text AS banned_at, reason, path
             FROM platform.probe_bans
             ORDER BY banned_at DESC, client_address'
        );
        if ($statement === false) {
            throw new RuntimeException('probe bans could not be listed');
        }
        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $address = $row['address'] ?? '';
            $bannedAt = $row['banned_at'] ?? '';
            $reason = $row['reason'] ?? '';
            $path = $row['path'] ?? '';
            if (!is_string($address) || !is_string($bannedAt) || !is_string($reason) || !is_string($path)) {
                continue;
            }
            $rows[] = [
                'address' => $address,
                'banned_at' => $bannedAt,
                'reason' => $reason,
                'path' => $path,
            ];
        }

        return $rows;
    }

    public function lift(string $address): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM platform.probe_bans WHERE client_address = ?'
        );
        $statement->execute([$address]);

        return $statement->rowCount() === 1;
    }

    private function pruneStreaks(): void
    {
        $statement = $this->pdo->prepare(
            "DELETE FROM platform.probe_streaks
             WHERE updated_at <= clock_timestamp() - interval '24 hours'"
        );
        $statement->execute();
    }

    private static function clip(string $value, int $max): string
    {
        $matched = preg_match('/\A.{0,' . $max . '}/us', $value, $match);
        if ($matched !== 1) {
            return '';
        }

        return $match[0];
    }
}
