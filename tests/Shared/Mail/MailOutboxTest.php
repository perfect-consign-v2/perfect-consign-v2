<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Mail;

use InvalidArgumentException;
use PerfectConsign\Shared\Mail\MailOutbox;
use PDO;
use PHPUnit\Framework\TestCase;

final class MailOutboxTest extends TestCase
{
    public function testInvalidTenantIsRefused(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('prepare');
        $outbox = new MailOutbox($pdo);
        $this->expectException(InvalidArgumentException::class);
        $outbox->file('Bad Mall', MailOutbox::MAILBOX_MALL, 'purpose', 'a@b.co', 'Hi', 'Body');
    }

    public function testInvalidMailboxIsRefused(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('prepare');
        $outbox = new MailOutbox($pdo);
        $this->expectException(InvalidArgumentException::class);
        $outbox->file('mall', 'vendor', 'purpose', 'a@b.co', 'Hi', 'Body');
    }

    public function testEmptyPurposeIsRefused(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('prepare');
        $outbox = new MailOutbox($pdo);
        $this->expectException(InvalidArgumentException::class);
        $outbox->file('mall', MailOutbox::MAILBOX_MALL, '', 'a@b.co', 'Hi', 'Body');
    }

    public function testOverlongPurposeIsRefused(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('prepare');
        $outbox = new MailOutbox($pdo);
        $this->expectException(InvalidArgumentException::class);
        $outbox->file('mall', MailOutbox::MAILBOX_MALL, str_repeat('p', 65), 'a@b.co', 'Hi', 'Body');
    }

    public function testMalformedAddressIsRefused(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('prepare');
        $outbox = new MailOutbox($pdo);
        $this->expectException(InvalidArgumentException::class);
        $outbox->file('mall', MailOutbox::MAILBOX_MALL, 'purpose', 'not-an-email', 'Hi', 'Body');
    }

    public function testOverlongAddressIsRefused(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('prepare');
        $outbox = new MailOutbox($pdo);
        $local = str_repeat('a', 190);
        $this->expectException(InvalidArgumentException::class);
        $outbox->file('mall', MailOutbox::MAILBOX_MALL, 'purpose', $local . '@example.com', 'Hi', 'Body');
    }

    public function testAddressWithNewlineIsRefused(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('prepare');
        $outbox = new MailOutbox($pdo);
        $this->expectException(InvalidArgumentException::class);
        $outbox->file('mall', MailOutbox::MAILBOX_MALL, 'purpose', "a@\nb.co", 'Hi', 'Body');
    }

    public function testBlankSubjectIsRefused(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('prepare');
        $outbox = new MailOutbox($pdo);
        $this->expectException(InvalidArgumentException::class);
        $outbox->file('mall', MailOutbox::MAILBOX_MALL, 'purpose', 'a@b.co', '', 'Body');
    }

    public function testOverlongSubjectIsRefused(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('prepare');
        $outbox = new MailOutbox($pdo);
        $this->expectException(InvalidArgumentException::class);
        $outbox->file('mall', MailOutbox::MAILBOX_MALL, 'purpose', 'a@b.co', str_repeat('s', 201), 'Body');
    }

    public function testSubjectWithNewlineIsRefused(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('prepare');
        $outbox = new MailOutbox($pdo);
        $this->expectException(InvalidArgumentException::class);
        $outbox->file('mall', MailOutbox::MAILBOX_MALL, 'purpose', 'a@b.co', "Hi\nthere", 'Body');
    }

    public function testEmptyBodyIsRefused(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('prepare');
        $outbox = new MailOutbox($pdo);
        $this->expectException(InvalidArgumentException::class);
        $outbox->file('mall', MailOutbox::MAILBOX_MALL, 'purpose', 'a@b.co', 'Hi', '');
    }

    public function testOverlongBodyIsRefused(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('prepare');
        $outbox = new MailOutbox($pdo);
        $this->expectException(InvalidArgumentException::class);
        $outbox->file('mall', MailOutbox::MAILBOX_MALL, 'purpose', 'a@b.co', 'Hi', str_repeat('b', 20001));
    }

    public function testOpenRefusesMalformedIds(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->never())->method('prepare');
        $outbox = new MailOutbox($pdo);
        self::assertNull($outbox->open('not-a-uuid'));
    }
}
