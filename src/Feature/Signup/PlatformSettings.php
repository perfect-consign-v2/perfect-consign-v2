<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Signup;

use PDO;
use RuntimeException;

final class PlatformSettings
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function storeSignupEnabled(): bool
    {
        return $this->boolSetting('store_signup_enabled', false);
    }

    public function requireEmailVerification(): bool
    {
        return $this->boolSetting('store_signup_require_email_verification', true);
    }

    public function setStoreSignupEnabled(bool $enabled): void
    {
        $this->put('store_signup_enabled', $enabled);
    }

    public function setRequireEmailVerification(bool $required): void
    {
        $this->put('store_signup_require_email_verification', $required);
    }

    private function boolSetting(string $key, bool $whenMissing): bool
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT value FROM platform.settings WHERE key = ?'
            );
            $statement->execute([$key]);
        } catch (\PDOException) {
            return $whenMissing;
        }
        $value = $statement->fetchColumn();
        if ($value === false || $value === null) {
            return $whenMissing;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (!is_string($value)) {
            return $whenMissing;
        }
        $trimmed = trim($value);
        if ($trimmed === 'true' || $trimmed === '1') {
            return true;
        }
        if ($trimmed === 'false' || $trimmed === '0' || $trimmed === '') {
            return false;
        }
        $decoded = json_decode($trimmed, true);

        return $decoded === true;
    }

    private function put(string $key, bool $enabled): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO platform.settings (key, value) VALUES (?, ?::jsonb)
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value'
        );
        $statement->execute([$key, $enabled ? 'true' : 'false']);
        if ($statement->rowCount() < 1) {
            throw new RuntimeException('store signup setting was not saved');
        }
    }
}
