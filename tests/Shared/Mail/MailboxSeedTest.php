<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Mail;

use PDO;
use PerfectConsign\Shared\Mail\MailboxSeed;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MailboxSeedTest extends TestCase
{
    public function testAResetWithoutTheMailboxRefuses(): void
    {
        $address = getenv('STALL_GMAIL_ADDRESS');
        $password = getenv('STALL_GMAIL_APP_PASSWORD');
        putenv('STALL_GMAIL_ADDRESS');
        putenv('STALL_GMAIL_APP_PASSWORD');
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('STALL_GMAIL_ADDRESS and STALL_GMAIL_APP_PASSWORD are required');
            MailboxSeed::plant($this->createStub(PDO::class));
        } finally {
            $this->restore('STALL_GMAIL_ADDRESS', $address);
            $this->restore('STALL_GMAIL_APP_PASSWORD', $password);
        }
    }

    public function testOneBlankMailboxFieldIsRefused(): void
    {
        $address = getenv('STALL_GMAIL_ADDRESS');
        $password = getenv('STALL_GMAIL_APP_PASSWORD');
        $cases = [
            ['', 'abcdefghijklmnop'],
            ['shop@example.test', ''],
        ];
        try {
            foreach ($cases as [$mail, $secret]) {
                putenv('STALL_GMAIL_ADDRESS=' . $mail);
                putenv('STALL_GMAIL_APP_PASSWORD=' . $secret);
                try {
                    MailboxSeed::plant($this->createStub(PDO::class));
                    self::fail('a blank mailbox field was planted');
                } catch (RuntimeException $error) {
                    self::assertSame('STALL_GMAIL_ADDRESS and STALL_GMAIL_APP_PASSWORD are required', $error->getMessage());
                }
            }
            putenv('STALL_GMAIL_ADDRESS');
            putenv('STALL_GMAIL_APP_PASSWORD=abcdefghijklmnop');
            try {
                MailboxSeed::plant($this->createStub(PDO::class));
                self::fail('a missing address was planted');
            } catch (RuntimeException $error) {
                self::assertSame('STALL_GMAIL_ADDRESS and STALL_GMAIL_APP_PASSWORD are required', $error->getMessage());
            }
            putenv('STALL_GMAIL_ADDRESS=shop@example.test');
            putenv('STALL_GMAIL_APP_PASSWORD');
            try {
                MailboxSeed::plant($this->createStub(PDO::class));
                self::fail('a missing password was planted');
            } catch (RuntimeException $error) {
                self::assertSame('STALL_GMAIL_ADDRESS and STALL_GMAIL_APP_PASSWORD are required', $error->getMessage());
            }
        } finally {
            $this->restore('STALL_GMAIL_ADDRESS', $address);
            $this->restore('STALL_GMAIL_APP_PASSWORD', $password);
        }
    }

    private function restore(string $name, mixed $value): void
    {
        if (is_string($value) && $value !== '') {
            putenv($name . '=' . $value);

            return;
        }
        putenv($name);
    }
}
