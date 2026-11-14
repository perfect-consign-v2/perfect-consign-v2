<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Jobs;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Jobs\DigestJob;
use PerfectConsign\Shared\Mail\MailBounce;
use PerfectConsign\Shared\Mail\MailProgress;
use PerfectConsign\Shared\Mail\OutboundMail;
use PerfectConsign\Tests\Support\ScriptPdo;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

final class DigestJobCoverageTest extends TestCase
{
    public function testDigestSkipsBlankSentAndUnearnedLettersThenSendsTheRest(): void
    {
        $previous = getenv('STALL_MAIL_FILE');
        $file = tempnam(sys_get_temp_dir(), 'pc-digest-');
        if ($file === false) {
            self::fail('the mail file could not be created');
        }
        putenv('STALL_MAIL_FILE=' . $file);
        $pdo = $this->vendors([
            ['id' => 'v-blank', 'email' => null, 'name' => 'Blank'],
            ['id' => 'v-sent', 'email' => 'sent@example.test', 'name' => 'Sent'],
            ['id' => 'v-low', 'email' => 'low@example.test', 'name' => 'Low'],
            ['id' => 'v-go', 'email' => 'go@example.test', 'name' => 'Go'],
        ]);
        $pdo->pushPrepare('SELECT 1 AS sent', [['sent' => '1']]);
        $pdo->pushPrepare('SELECT 1 AS sent', []);
        $pdo->pushPrepare('vendor_payable', [['total' => '0']]);
        $pdo->pushPrepare('booth_rent_receivable', [['total' => '-5']]);
        $pdo->pushPrepare('vendor_receivable', [['total' => '0']]);
        $pdo->pushPrepare('SELECT 1 AS sent', []);
        $pdo->pushPrepare('vendor_payable', [['total' => '2500']]);
        $pdo->pushPrepare('booth_rent_receivable', [['total' => '10']]);
        $pdo->pushPrepare('vendor_receivable', [['total' => '0']]);
        try {
            $this->job($pdo)->digest('mall', '2026-11-08');
            $body = file_get_contents($file);
            self::assertIsString($body);
            self::assertStringContainsString('To: go@example.test', $body);
            self::assertStringNotContainsString('low@example.test', $body);
        } finally {
            $this->restoreMail($previous);
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testAFailedReminderLeavesTheLetterWaiting(): void
    {
        $previous = getenv('STALL_MAIL_FILE');
        putenv('STALL_MAIL_FILE');
        $pdo = $this->vendors([
            ['id' => 'v-zero', 'email' => 'zero@example.test', 'name' => 'Zero'],
            ['id' => 'v-due', 'email' => 'due@example.test', 'name' => 'Due'],
        ]);
        $pdo->pushPrepare('SELECT 1 AS sent', []);
        $pdo->pushPrepare('vendor_payable', [['total' => '0']]);
        $pdo->pushPrepare('booth_rent_receivable', [['total' => '0']]);
        $pdo->pushPrepare('vendor_receivable', [['total' => '0']]);
        $pdo->pushPrepare('SELECT 1 AS sent', []);
        $pdo->pushPrepare('vendor_payable', [['total' => '0']]);
        $pdo->pushPrepare('booth_rent_receivable', [['total' => '1500']]);
        $pdo->pushPrepare('vendor_receivable', [['total' => '0']]);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('a letter is still waiting to be sent');
            $this->job($pdo)->remind('mall', '2026-11-08');
        } finally {
            $this->restoreMail($previous);
        }
    }

    /**
     * @param list<array{id: string, email: string|null, name: string}> $vendors
     */
    private function vendors(array $vendors): ScriptPdo
    {
        $pdo = new ScriptPdo();
        $pdo->onQuery('FROM vendors', $vendors);

        return $pdo;
    }

    private function job(ScriptPdo $pdo): DigestJob
    {
        $logger = new NullLogger();

        return new DigestJob(
            $pdo,
            new OutboundMail($logger),
            new MailBounce(new Statements($pdo)),
            $logger,
            new MailProgress(),
        );
    }

    private function restoreMail(mixed $previous): void
    {
        if (is_string($previous) && $previous !== '') {
            putenv('STALL_MAIL_FILE=' . $previous);

            return;
        }
        putenv('STALL_MAIL_FILE');
    }
}
