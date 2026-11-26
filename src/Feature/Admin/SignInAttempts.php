<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Admin;

use PDO;

/**
 * Reads platform.sign_in_attempts for Admin, Sign-ins: newest first, at most
 * MOST_LISTED rows, filtered by email, client address, and outcome.
 */
final class SignInAttempts
{
    public const MOST_LISTED = 200;

    public const OUTCOMES = [
        'ok' => 'Signed in',
        'wrong_password' => 'Wrong password',
        'unknown_email' => 'No such account',
        'locked_pair' => 'Refused: too many from this address',
        'locked_address' => 'Refused: this address tried too many accounts',
        'locked_email' => 'Refused: too many for this account',
        'password_reset' => 'Password reset',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<array{at: string, door: string, email: string, store: string, username: string, address: string, agent: string, outcome: string, trusted: bool}>
     */
    public function listed(string $email, string $address, string $outcome): array
    {
        $email = strtolower(trim($email));
        $address = trim($address);
        $outcome = array_key_exists($outcome, self::OUTCOMES) ? $outcome : '';
        $statement = $this->pdo->prepare(
            "SELECT a.attempted_at::text AS at, a.door, coalesce(a.email_tried, '') AS email,
                    coalesce(t.slug, '') AS store, coalesce(a.username, '') AS username,
                    a.client_address AS address, a.user_agent AS agent, a.outcome, a.trusted_device AS trusted
             FROM platform.sign_in_attempts AS a
             LEFT JOIN platform.tenants AS t ON t.id = a.tenant_id
             WHERE (?::text = '' OR a.email_tried = ?::text OR a.account_key = 'admin:' || ?::text)
               AND (?::text = '' OR a.client_address = ?::text)
               AND (?::text = '' OR a.outcome = ?::text)
             ORDER BY a.attempted_at DESC, a.id DESC
             LIMIT " . self::MOST_LISTED
        );
        $statement->execute([$email, $email, $email, $address, $address, $outcome, $outcome]);
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rows[] = [
                'at' => self::text($row['at'] ?? null),
                'door' => self::text($row['door'] ?? null),
                'email' => self::text($row['email'] ?? null),
                'store' => self::text($row['store'] ?? null),
                'username' => self::text($row['username'] ?? null),
                'address' => self::text($row['address'] ?? null),
                'agent' => self::text($row['agent'] ?? null),
                'outcome' => self::text($row['outcome'] ?? null),
                'trusted' => in_array($row['trusted'] ?? null, [true, 't', 'true', 1, '1'], true),
            ];
        }

        return $rows;
    }

    public static function words(string $outcome): string
    {
        return self::OUTCOMES[$outcome] ?? $outcome;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
