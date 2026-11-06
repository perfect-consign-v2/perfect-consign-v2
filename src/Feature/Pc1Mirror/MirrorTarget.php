<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Pc1Mirror;

use PerfectConsign\Feature\Backup\BackupConnection;
use PerfectConsign\Feature\Backup\BackupException;

final class MirrorTarget
{
    public function __construct(
        public readonly string $name,
        public readonly BackupConnection $connection,
    ) {
    }

    public static function open(string $name): self
    {
        return match ($name) {
            'local' => self::local(),
            'v2' => self::v2(),
            default => throw new MirrorException('mirror target must be local or v2'),
        };
    }

    private static function local(): self
    {
        try {
            $connection = BackupConnection::admin();
        } catch (BackupException $error) {
            throw new MirrorException($error->getMessage(), 0, $error);
        }
        if (!$connection->isLocalRestoreTarget()) {
            throw new MirrorException('local mirror target must be localhost:5436 database consign');
        }

        return new self('local', $connection);
    }

    private static function v2(): self
    {
        $host = self::requireEnv('STALL_MIRROR_V2_DB_HOST', 'STALL_DB_HOST');
        self::assertV2Host($host);
        try {
            $connection = new BackupConnection(
                $host,
                self::requireEnv('STALL_MIRROR_V2_DB_PORT', 'STALL_DB_PORT'),
                self::requireEnv('STALL_MIRROR_V2_DB_NAME', 'STALL_DB_NAME'),
                self::requireEnv('STALL_MIRROR_V2_DB_USER', 'STALL_DB_USER'),
                self::requireEnv('STALL_MIRROR_V2_DB_PASSWORD', 'STALL_DB_PASSWORD'),
            );
        } catch (BackupException $error) {
            throw new MirrorException($error->getMessage(), 0, $error);
        }

        return new self('v2', $connection);
    }

    private static function assertV2Host(string $host): void
    {
        $lower = strtolower($host);
        if ($lower === MirrorConstants::PROD_HOST || str_contains($lower, 'api.perfectconsign.com')) {
            throw new MirrorException('v2 mirror target must not be production');
        }
        if (self::hostAllowed($host, $lower)) {
            return;
        }
        throw new MirrorException(
            'v2 mirror host must be v2.perfectconsign.com, localhost, or STALL_MIRROR_V2_ALLOW_HOST'
        );
    }

    private static function hostAllowed(string $host, string $lower): bool
    {
        $allowed = self::env('STALL_MIRROR_V2_ALLOW_HOST');
        if (is_string($allowed) && $allowed !== '') {
            return strcasecmp($host, $allowed) === 0;
        }

        return $lower === 'v2.perfectconsign.com'
            || str_ends_with($lower, '.v2.perfectconsign.com')
            || $lower === '127.0.0.1'
            || $lower === 'localhost';
    }

    private static function requireEnv(string $primary, string $fallback): string
    {
        $value = self::env($primary) ?? self::env($fallback);
        if ($value === null) {
            throw new MirrorException('STALL_MIRROR_V2_DB_* (or STALL_DB_* on the v2 host) is required');
        }

        return $value;
    }

    private static function env(string $key): ?string
    {
        $value = getenv($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
