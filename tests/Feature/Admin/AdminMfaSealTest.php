<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Admin;

use PerfectConsign\Feature\Admin\Mfa\AdminMfaKeyFile;
use PerfectConsign\Feature\Admin\Mfa\AdminMfaSeal;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AdminMfaSealTest extends TestCase
{
    public function testASecretRoundTripsAndATamperedBoxDoesNot(): void
    {
        $plain = '12345678901234567890';
        $sealed = AdminMfaSeal::seal($plain);
        self::assertStringStartsWith('v1:', $sealed);
        self::assertSame($plain, AdminMfaSeal::open($sealed));
        self::assertNotSame($sealed, AdminMfaSeal::seal($plain));

        $tampered = substr($sealed, 0, -2) . (substr($sealed, -2) === 'aa' ? 'bb' : 'aa');
        $this->expectException(RuntimeException::class);
        AdminMfaSeal::open($tampered);
    }

    public function testAnEmptySecretIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AdminMfaSeal::seal('');
    }

    public function testAMissingKeyIsWrittenUntilAnAdminIsEnrolled(): void
    {
        $path = $this->envFile("APP_ENV=test\n");
        $enrolled = $this->envFile("STALL_ADMIN_MFA_KEY=\n");
        try {
            AdminMfaKeyFile::present($path, 0);
            $key = AdminMfaKeyFile::stored($path);
            self::assertIsString($key);
            AdminMfaKeyFile::present($path, 0);
            self::assertSame($key, AdminMfaKeyFile::stored($path));
            try {
                AdminMfaKeyFile::present($enrolled, 1);
                self::fail('a new key was invented after an authenticator was stored');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('already sealed', $error->getMessage());
            }
            $text = file_get_contents($enrolled);
            self::assertIsString($text);
            self::assertSame("STALL_ADMIN_MFA_KEY=\n", $text);
        } finally {
            $this->remove($path);
            $this->remove($enrolled);
        }
    }

    public function testAnInvalidKeyIsNotOverwritten(): void
    {
        $path = $this->envFile("STALL_ADMIN_MFA_KEY=not-a-key\n");
        try {
            try {
                AdminMfaKeyFile::present($path, 0);
                self::fail('an invalid authenticator key was accepted');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('not a secret-box key', $error->getMessage());
            }
            $text = file_get_contents($path);
            self::assertIsString($text);
            self::assertStringContainsString('STALL_ADMIN_MFA_KEY=not-a-key', $text);
        } finally {
            $this->remove($path);
        }
    }

    public function testAMissingEnvironmentFileIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        AdminMfaKeyFile::stored(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'missing-admin-mfa-' . bin2hex(random_bytes(4)) . '.env');
    }

    private function envFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pc-mfa-env-');
        if ($path === false) {
            self::fail('the env file could not be created');
        }
        if (file_put_contents($path, $contents) === false) {
            self::fail('the env file could not be written');
        }

        return $path;
    }

    private function remove(string $path): void
    {
        if (is_file($path) && !unlink($path)) {
            self::fail('the env file could not be removed');
        }
    }
}
