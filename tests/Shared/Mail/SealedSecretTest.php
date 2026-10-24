<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Mail;

use PerfectConsign\Shared\Mail\SealedSecret;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SealedSecretTest extends TestCase
{
    public function testASealedPasswordOpensToTheSameValue(): void
    {
        self::assertSame('abcdefghijklmnop', SealedSecret::open(SealedSecret::seal('abcdefghijklmnop')));
    }

    public function testAPlainPasswordIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the mail password is not sealed');
        SealedSecret::open('abcdefghijklmnop');
    }

    public function testAMissingKeyIsRefused(): void
    {
        $saved = getenv('STALL_MAIL_KEY');
        putenv('STALL_MAIL_KEY');
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('STALL_MAIL_KEY is required');
            SealedSecret::seal('abcdefghijklmnop');
        } finally {
            $this->restore($saved);
        }
    }

    public function testAShortKeyIsRefused(): void
    {
        $saved = getenv('STALL_MAIL_KEY');
        putenv('STALL_MAIL_KEY=' . sodium_bin2base64('short', SODIUM_BASE64_VARIANT_ORIGINAL));
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('STALL_MAIL_KEY is not a secret-box key');
            SealedSecret::seal('abcdefghijklmnop');
        } finally {
            $this->restore($saved);
        }
    }

    private function restore(mixed $saved): void
    {
        if (is_string($saved) && $saved !== '') {
            putenv('STALL_MAIL_KEY=' . $saved);

            return;
        }
        putenv('STALL_MAIL_KEY');
    }
}
