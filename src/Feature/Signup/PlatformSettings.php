<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Signup;

use PDO;
use PerfectConsign\Shared\Database\PgBool;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class PlatformSettings
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function storeSignupEnabled(): bool
    {
        return $this->boolSetting('store_signup_enabled', false);
    }

    public function setStoreSignupEnabled(bool $enabled): void
    {
        $this->put('store_signup_enabled', $enabled);
    }

    public function adminMfaEnabled(): bool
    {
        return $this->boolSetting('admin_mfa_enabled', true);
    }

    public function setAdminMfaEnabled(bool $enabled): void
    {
        $this->put('admin_mfa_enabled', $enabled);
    }

    private function boolSetting(string $key, bool $whenMissing): bool
    {
        $value = $this->rawSetting($key);
        if ($value === null) {
            return $whenMissing;
        }

        return $this->parseBool($value, $whenMissing);
    }

    private function rawSetting(string $key): mixed
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT value FROM platform.settings WHERE key = ?'
            );
            $statement->execute([$key]);
        } catch (\PDOException $error) {
            $this->logger->error('platform setting read failed', [
                'key' => $key,
                'message' => $error->getMessage(),
            ]);
            throw $error;
        }
        $value = $statement->fetchColumn();
        if ($value === false || $value === null) {
            return null;
        }

        return $value;
    }

    private function parseBool(mixed $value, bool $whenMissing): bool
    {
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

        return json_decode($trimmed, true) === true;
    }

    private function put(string $key, bool $enabled): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO platform.settings (key, value) VALUES (?, ?::jsonb)
             ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value'
        );
        $statement->execute([$key, PgBool::literal($enabled)]);
        if ($statement->rowCount() < 1) {
            throw new RuntimeException('platform setting was not saved');
        }
    }
}
