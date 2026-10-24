<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Mail;

use PerfectConsign\Shared\Mail\MailKeyFile;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MailKeyFileTest extends TestCase
{
    public function testAMissingKeyIsStoredAndLeftAlone(): void
    {
        $path = $this->file("STALL_DB_HOST=127.0.0.1\n");
        try {
            MailKeyFile::present($path);
            $text = file_get_contents($path);
            self::assertIsString($text);
            self::assertSame(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, strlen($this->key($text)));
            MailKeyFile::present($path);
            self::assertSame($text, file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    public function testAnEmptyKeyIsReplaced(): void
    {
        $path = $this->file("STALL_MAIL_KEY=\nSTALL_DB_HOST=127.0.0.1\n");
        try {
            MailKeyFile::present($path);
            $text = file_get_contents($path);
            self::assertIsString($text);
            self::assertStringContainsString("STALL_DB_HOST=127.0.0.1\n", $text);
            self::assertSame(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, strlen($this->key($text)));
        } finally {
            unlink($path);
        }
    }

    public function testAMissingFileIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the environment file is missing');
        MailKeyFile::present(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pc-env-missing-' . bin2hex(random_bytes(4)));
    }

    private function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pc-env-');
        self::assertIsString($path);
        $written = file_put_contents($path, $contents);
        self::assertNotFalse($written);

        return $path;
    }

    private function key(string $text): string
    {
        self::assertSame(1, preg_match('/^STALL_MAIL_KEY=(.+)$/m', $text, $match));
        $raw = sodium_base642bin($match[1], SODIUM_BASE64_VARIANT_ORIGINAL);
        self::assertIsString($raw);

        return $raw;
    }
}
