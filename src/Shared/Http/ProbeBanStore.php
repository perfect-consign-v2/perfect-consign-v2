<?php

declare(strict_types=1);

namespace PerfectConsign\Shared\Http;

use PDO;
use RuntimeException;

/**
 * Probe bans follow platform.probe_rules. platform.probe_hit counts a hit and
 * starts a ban at the limit. platform.probe_ban starts a ban now. Every ban ends.
 */
final class ProbeBanStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function banned(string $address): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM platform.probe_bans
             WHERE client_address = ?
               AND banned_until > clock_timestamp()'
        );
        $statement->execute([$address]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * True when this call started a ban. False when one was already running.
     */
    public function insertBan(string $address, string $reason, string $agent, string $path): bool
    {
        return $this->started(
            'SELECT platform.probe_ban(?::text, ?::text, ?::text, ?::text)::text',
            [$address, $reason, $agent, $path],
            'the probe ban was not stored',
        );
    }

    /**
     * Count one probe hit. True when this hit started a ban.
     */
    public function hit(string $address, string $agent, string $path): bool
    {
        return $this->started(
            'SELECT platform.probe_hit(?::text, ?::text, ?::text)::text',
            [$address, $agent, $path],
            'the probe hit was not stored',
        );
    }

    /**
     * @return list<array{address: string, banned_at: string, banned_until: string, active: bool, strikes: int, reason: string, path: string}>
     */
    public function listBans(): array
    {
        $statement = $this->pdo->query(
            "SELECT client_address AS address,
                    banned_at::text AS banned_at,
                    banned_until::text AS banned_until,
                    CASE WHEN banned_until > clock_timestamp() THEN 'yes' ELSE 'no' END AS active,
                    strikes::text AS strikes,
                    reason,
                    path
             FROM platform.probe_bans
             ORDER BY banned_at DESC, client_address"
        );
        if ($statement === false) {
            throw new RuntimeException('probe bans could not be listed');
        }
        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            $listed = is_array($row) ? self::listed($row) : null;
            if ($listed !== null) {
                $rows[] = $listed;
            }
        }

        return $rows;
    }

    public function lift(string $address): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM platform.probe_bans WHERE client_address = ?'
        );
        $statement->execute([$address]);
        $lifted = $statement->rowCount() === 1;
        $streak = $this->pdo->prepare(
            'DELETE FROM platform.probe_streaks WHERE client_address = ?'
        );
        $streak->execute([$address]);

        return $lifted;
    }

    /**
     * @param list<string> $params
     */
    private function started(string $sql, array $params, string $failure): bool
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $started = $statement->fetchColumn();
        if ($started === 'true') {
            return true;
        }
        if ($started === 'false') {
            return false;
        }

        throw new RuntimeException($failure);
    }

    /**
     * @param array<mixed> $row
     * @return array{address: string, banned_at: string, banned_until: string, active: bool, strikes: int, reason: string, path: string}|null
     */
    private static function listed(array $row): ?array
    {
        $text = [];
        foreach (['address', 'banned_at', 'banned_until', 'active', 'strikes', 'reason', 'path'] as $key) {
            $value = $row[$key] ?? null;
            if (!is_string($value)) {
                return null;
            }
            $text[$key] = $value;
        }
        if (!ctype_digit($text['strikes'])) {
            return null;
        }

        return [
            'address' => $text['address'],
            'banned_at' => $text['banned_at'],
            'banned_until' => $text['banned_until'],
            'active' => $text['active'] === 'yes',
            'strikes' => (int) $text['strikes'],
            'reason' => $text['reason'],
            'path' => $text['path'],
        ];
    }
}
