<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Mail;

use PDO;
use PDOStatement;
use PerfectConsign\Shared\Mail\MailboxSeal;
use PerfectConsign\Shared\Mail\SealedSecret;
use PHPUnit\Framework\TestCase;

final class MailboxSealTest extends TestCase
{
    public function testAPlainPasswordIsSealed(): void
    {
        $listed = $this->createStub(PDOStatement::class);
        $listed->method('fetchAll')->willReturn(['abcdefghijklmnop']);
        $update = $this->createMock(PDOStatement::class);
        $update->expects($this->once())->method('execute')->willReturnCallback(function (array $values): bool {
            self::assertSame('abcdefghijklmnop', SealedSecret::open($values[0]));
            self::assertSame('abcdefghijklmnop', $values[1]);

            return true;
        });
        $pdo = $this->createStub(PDO::class);
        $pdo->method('query')->willReturn($listed);
        $pdo->method('prepare')->willReturn($update);

        MailboxSeal::sealPlain($pdo);
    }

    public function testAnEmptyMailboxIsLeftAlone(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('query')->willReturn(false);
        $pdo->expects($this->never())->method('prepare');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('the mailbox password could not be sealed');
        MailboxSeal::sealPlain($pdo);
    }
}
