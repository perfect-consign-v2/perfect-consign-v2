<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Mall;

use PerfectConsign\Feature\Mall\ReceiptMailDraft;
use PHPUnit\Framework\TestCase;

final class ReceiptMailDraftTest extends TestCase
{
    public function testTheSenderIsTheServerOrGmail(): void
    {
        self::assertSame('server', ReceiptMailDraft::via(' server '));
        self::assertSame('gmail', ReceiptMailDraft::via('gmail'));
        $this->expectExceptionMessage('choose the server or Gmail');
        ReceiptMailDraft::via('outlook');
    }

    public function testAGmailAddressAndAppPasswordAreChecked(): void
    {
        self::assertSame('ada@gmail.com', ReceiptMailDraft::address(' ada@gmail.com '));
        self::assertSame('abcdefghijklmnop', ReceiptMailDraft::password('abcd efgh ijkl mnop'));
        self::assertNull(ReceiptMailDraft::address(''));
        self::assertNull(ReceiptMailDraft::password(''));
    }

    public function testAMailboxThatIsNotGmailIsRefused(): void
    {
        $this->expectExceptionMessage('that address is not a Gmail address');
        ReceiptMailDraft::address('ada@example.com');
    }
}
