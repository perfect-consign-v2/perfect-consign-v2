<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Mail;

use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Mail\MailProgress;
use PHPUnit\Framework\TestCase;

final class MailProgressTest extends TestCase
{
    public function testALetterAlreadySentIsSkipped(): void
    {
        $statements = $this->createMock(Statements::class);
        $statements->expects($this->once())->method('bound')->willReturn([['sent' => 1]]);
        $progress = new MailProgress();

        self::assertTrue($progress->sent($statements, 'mall', 'vendor-digest', '2026-01-02', 'ada@example.test'));
    }

    public function testANewLetterIsRecorded(): void
    {
        $statements = $this->createMock(Statements::class);
        $statements->expects($this->once())->method('bound')->willReturn([]);
        $statements->expects($this->once())->method('change');
        $progress = new MailProgress();

        self::assertFalse($progress->sent($statements, 'mall', 'sale-notice', '2026-01-02', 'ada@example.test'));
        $progress->record($statements, 'mall', 'sale-notice', '2026-01-02', 'ada@example.test');
    }
}
