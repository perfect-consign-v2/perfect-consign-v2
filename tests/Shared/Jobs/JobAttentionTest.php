<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Jobs;

use PDO;
use PDOStatement;
use PerfectConsign\Shared\Jobs\JobAttention;
use PerfectConsign\Shared\Mail\GmailSender;
use PerfectConsign\Shared\Mail\OutboundMail;
use PerfectConsign\Shared\Mail\PlatformMailbox;
use PerfectConsign\Shared\Mail\PlatformOperatorNotice;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class JobAttentionTest extends TestCase
{
    public function testAnOverdueNoticeIsSentOnce(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pc-job-');
        if ($path === false) {
            self::fail('the mail file could not be created');
        }
        $previousFile = getenv('STALL_MAIL_FILE');
        $previousAdmin = getenv('STALL_PLATFORM_ADMIN_EMAIL');
        putenv('STALL_MAIL_FILE=' . $path);
        putenv('STALL_PLATFORM_ADMIN_EMAIL=ops@example.com');
        $batches = [[$this->jobRow()], [], [], []];
        $stamps = 0;
        try {
            $attention = $this->attention($batches, $stamps);
            $attention->report();
            $attention->report();
            $text = file_get_contents($path);
            self::assertIsString($text);
            self::assertSame(1, substr_count($text, 'Subject: Perfect Consign: jobs are overdue'));
            self::assertStringContainsString('webprobe vendor-letter', $text);
            self::assertStringContainsString('To: ada@example.test', $text);
            self::assertSame(1, $stamps);
        } finally {
            $this->restore($previousFile, $previousAdmin, $path);
        }
    }

    public function testAFailedNoticeDoesNotStamp(): void
    {
        $previousFile = getenv('STALL_MAIL_FILE');
        $previousAdmin = getenv('STALL_PLATFORM_ADMIN_EMAIL');
        putenv('STALL_MAIL_FILE=' . sys_get_temp_dir());
        putenv('STALL_PLATFORM_ADMIN_EMAIL=ops@example.com');
        $batches = [[$this->jobRow()], []];
        $stamps = 0;
        try {
            $attention = $this->attention($batches, $stamps);
            $attention->report();
            self::fail('a directory mail file was accepted');
        } catch (RuntimeException $error) {
            self::assertSame('mail file write failed', $error->getMessage());
            self::assertSame(0, $stamps);
        } finally {
            $this->restore($previousFile, $previousAdmin, null);
        }
    }

    public function testAMissingAdminAddressStampsWithoutSending(): void
    {
        $previousFile = getenv('STALL_MAIL_FILE');
        $previousAdmin = getenv('STALL_PLATFORM_ADMIN_EMAIL');
        putenv('STALL_MAIL_FILE');
        putenv('STALL_PLATFORM_ADMIN_EMAIL');
        $batches = [[$this->jobRow()], []];
        $stamps = 0;
        try {
            $this->attention($batches, $stamps)->report();
            self::assertSame(1, $stamps);
        } finally {
            $this->restore($previousFile, $previousAdmin, null);
        }
    }

    /**
     * @param list<list<array<string, mixed>>> $batches
     */
    private function attention(array &$batches, int &$stamps): JobAttention
    {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$batches, &$stamps): PDOStatement {
            $statement = $this->createStub(PDOStatement::class);
            if (str_contains($sql, 'UPDATE platform.jobs')) {
                $statement->method('execute')->willReturnCallback(static function () use (&$stamps): bool {
                    $stamps++;

                    return true;
                });

                return $statement;
            }
            $rows = array_shift($batches);
            if (!is_array($rows)) {
                $rows = [];
            }
            $statement->method('execute')->willReturn(true);
            $statement->method('fetch')->willReturnCallback(static function () use (&$rows): mixed {
                if ($rows === []) {
                    return false;
                }

                return array_shift($rows);
            });

            return $statement;
        });
        $logger = $this->createStub(LoggerInterface::class);
        $mail = new OutboundMail($logger);
        $notices = new PlatformOperatorNotice($logger);

        return new JobAttention(
            $pdo,
            $mail,
            new PlatformMailbox($mail, new GmailSender($notices), $notices),
            $notices,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function jobRow(): array
    {
        return [
            'id' => '01900000-0000-7000-8000-00000000d203',
            'tenant' => 'webprobe',
            'kind' => 'vendor-letter',
            'run_at' => '2026-11-19 06:20:51+00',
            'finished_at' => '',
            'attempts' => 0,
            'error' => '',
            'subject' => 'Sweep 5cc961',
            'recipients' => 'ada@example.test',
            'mail_via' => '',
            'mailbox' => '',
            'payload_date' => '2026-11-18',
            'due' => 't',
        ];
    }

    private function restore(string|false $file, string|false $admin, ?string $path): void
    {
        if (is_string($file) && $file !== '') {
            putenv('STALL_MAIL_FILE=' . $file);
        } else {
            putenv('STALL_MAIL_FILE');
        }
        if (is_string($admin) && $admin !== '') {
            putenv('STALL_PLATFORM_ADMIN_EMAIL=' . $admin);
        } else {
            putenv('STALL_PLATFORM_ADMIN_EMAIL');
        }
        if (is_string($path) && is_file($path) && !unlink($path)) {
            self::fail('the mail file could not be removed');
        }
    }
}
