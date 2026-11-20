<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Support;

use PerfectConsign\Feature\Admin\Mfa\AdminMfaSeal;
use PerfectConsign\Feature\Admin\Mfa\RecoveryCode;
use PerfectConsign\Feature\Admin\Mfa\Totp;
use PDO;
use RuntimeException;

/**
 * The suite and the php -S child share one private admin prefix.
 */
final class AdminDoor
{
    public const FALLBACK = '/ci-admin-path-7f3a9c';

    public const SECRET = '12345678901234567890';

    public static function ensure(): void
    {
        $value = getenv('STALL_ADMIN_PATH');
        if (is_string($value) && $value !== '') {
            return;
        }
        putenv('STALL_ADMIN_PATH=' . self::FALLBACK);
        $_ENV['STALL_ADMIN_PATH'] = self::FALLBACK;
        $_SERVER['STALL_ADMIN_PATH'] = self::FALLBACK;
    }

    public static function prefix(): string
    {
        self::ensure();
        $value = getenv('STALL_ADMIN_PATH');
        if (!is_string($value) || $value === '') {
            return self::FALLBACK;
        }

        return $value;
    }

    public static function path(string $suffix): string
    {
        if ($suffix === '' || $suffix === '/') {
            return self::prefix();
        }

        return self::prefix() . $suffix;
    }

    public static function plant(PDO $pdo, string $email): void
    {
        self::clear($pdo, $email);
        $step = Totp::stepForTime(time()) - 2;
        if ($step < 0) {
            $step = 0;
        }
        $save = $pdo->prepare(
            'UPDATE platform.admins
             SET mfa_secret = ?,
                 mfa_confirmed_at = clock_timestamp(),
                 mfa_last_used_step = ?
             WHERE id = ?::uuid
               AND mfa_secret IS NULL'
        );
        $save->execute([AdminMfaSeal::seal(self::SECRET), $step, self::adminId($pdo, $email)]);
        if ($save->rowCount() !== 1) {
            throw new RuntimeException('the test authenticator was not saved');
        }
    }

    public static function clear(PDO $pdo, string $email): void
    {
        $id = self::adminId($pdo, $email);
        $burn = $pdo->prepare(
            'UPDATE platform.admin_mfa_recovery_codes
             SET used_at = clock_timestamp()
             WHERE admin_id = ?::uuid AND used_at IS NULL'
        );
        $burn->execute([$id]);
        $clear = $pdo->prepare(
            'UPDATE platform.admins
             SET mfa_secret = NULL, mfa_confirmed_at = NULL, mfa_last_used_step = NULL
             WHERE id = ?::uuid'
        );
        $clear->execute([$id]);
    }

    public static function recoveryCode(PDO $pdo, string $email): string
    {
        $codes = RecoveryCode::batch();
        $code = $codes[0];
        $insert = $pdo->prepare(
            'INSERT INTO platform.admin_mfa_recovery_codes (admin_id, code_hash)
             VALUES (?::uuid, ?)'
        );
        $insert->execute([
            self::adminId($pdo, $email),
            RecoveryCode::hash(RecoveryCode::normalize($code)),
        ]);

        return $code;
    }

    public static function waitUntilStep(int $step): void
    {
        $wait = ($step * Totp::PERIOD_SECONDS) - time();
        if ($wait > 0) {
            sleep($wait + 1);
        }
    }

    public static function signIn(FormClient $client, string $email, string $password, ?int $step = null): PageResponse
    {
        $client->get(self::path('/login'));
        $client->post(self::path('/login'), [
            'email' => $email,
            'password' => $password,
        ]);

        return $client->post(self::path('/mfa'), [
            'code' => Totp::codeForStep(self::SECRET, $step ?? Totp::stepForTime(time())),
        ]);
    }

    private static function adminId(PDO $pdo, string $email): string
    {
        $statement = $pdo->prepare('SELECT id::text FROM platform.admins WHERE lower(email) = lower(?)');
        $statement->execute([$email]);
        $id = $statement->fetchColumn();
        if (!is_string($id) || $id === '') {
            throw new RuntimeException('the test platform admin was not found');
        }

        return $id;
    }
}
