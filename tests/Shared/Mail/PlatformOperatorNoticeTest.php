<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Mail;

use PerfectConsign\Shared\Mail\MailDeliveryFailed;
use PerfectConsign\Shared\Mail\PlatformOperatorNotice;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class PlatformOperatorNoticeTest extends TestCase
{
    public function testAMailFailureIsLoggedAndEmailedToThePlatformAdmin(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pc-op-');
        if ($path === false) {
            self::fail('the mail file could not be created');
        }
        $previousFile = getenv('STALL_MAIL_FILE');
        $previousAdmin = getenv('STALL_PLATFORM_ADMIN_EMAIL');
        putenv('STALL_MAIL_FILE=' . $path);
        putenv('STALL_PLATFORM_ADMIN_EMAIL=ops@example.com');
        $log = new class extends AbstractLogger {
            /** @var list<string> */
            public array $errors = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if ($level === 'error') {
                    $this->errors[] = (string) $message;
                }
            }
        };
        try {
            $notices = new PlatformOperatorNotice($log);
            $notices->mailFailed('password reset mail was refused', new MailDeliveryFailed(535, 'auth'), [
                'slug' => 'mall',
            ]);
            $text = file_get_contents($path);
            self::assertIsString($text);
            self::assertStringContainsString('To: ops@example.com', $text);
            self::assertStringContainsString('Subject: Perfect Consign: mail delivery failed', $text);
            self::assertStringContainsString('Purpose: password reset mail was refused', $text);
            self::assertStringContainsString('SMTP status: 535', $text);
            self::assertContains('password reset mail was refused', $log->errors);
        } finally {
            if (is_string($previousFile) && $previousFile !== '') {
                putenv('STALL_MAIL_FILE=' . $previousFile);
            } else {
                putenv('STALL_MAIL_FILE');
            }
            if (is_string($previousAdmin) && $previousAdmin !== '') {
                putenv('STALL_PLATFORM_ADMIN_EMAIL=' . $previousAdmin);
            } else {
                putenv('STALL_PLATFORM_ADMIN_EMAIL');
            }
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testAServerFailureEmailsThePlatformAdmin(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pc-op-');
        if ($path === false) {
            self::fail('the mail file could not be created');
        }
        $previousFile = getenv('STALL_MAIL_FILE');
        $previousAdmin = getenv('STALL_PLATFORM_ADMIN_EMAIL');
        putenv('STALL_MAIL_FILE=' . $path);
        putenv('STALL_PLATFORM_ADMIN_EMAIL=ops@example.com');
        $log = new class extends AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void
            {
            }
        };
        try {
            (new PlatformOperatorNotice($log))->serverFailed('uncaught exception', [
                'path' => '/boom',
                'message' => 'nope',
            ]);
            $text = file_get_contents($path);
            self::assertIsString($text);
            self::assertStringContainsString('Subject: Perfect Consign: server error', $text);
            self::assertStringContainsString('path: /boom', $text);
            self::assertStringContainsString('message: nope', $text);
        } finally {
            if (is_string($previousFile) && $previousFile !== '') {
                putenv('STALL_MAIL_FILE=' . $previousFile);
            } else {
                putenv('STALL_MAIL_FILE');
            }
            if (is_string($previousAdmin) && $previousAdmin !== '') {
                putenv('STALL_PLATFORM_ADMIN_EMAIL=' . $previousAdmin);
            } else {
                putenv('STALL_PLATFORM_ADMIN_EMAIL');
            }
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
