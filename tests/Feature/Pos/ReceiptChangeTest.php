<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Pos;

use PerfectConsign\Feature\Pos\ReceiptChange;
use PerfectConsign\Ledger\DatabaseRefusal;
use PHPUnit\Framework\TestCase;

final class ReceiptChangeTest extends TestCase
{
    public function testChangeIsTheCashHandedBack(): void
    {
        $change = new ReceiptChange();
        self::assertSame(500, $change->due('10.00', 500));
        self::assertSame(199, $change->due('2.00', 1));
        self::assertSame(0, $change->due('1.00', 100));
        self::assertNull($change->due('', 500));
        self::assertNull($change->due('0', 500));
        self::assertNull($change->due('0.0', 500));
        self::assertNull($change->due('0.00', 500));
        self::assertNull($change->due('1.00', 0));
        self::assertNull($change->due('1.00', 200));
    }

    public function testTheReturnedPieceIsPricedFromTheReceipt(): void
    {
        $change = new ReceiptChange();
        $receipt = [
            'lines' => [
                ['item_id' => 'other', 'price' => 100, 'discount' => 0, 'tax' => 0],
                ['item_id' => 'piece', 'price' => 1000, 'discount' => 200, 'tax' => 80],
            ],
        ];
        self::assertSame(880, $change->owed($receipt, 'piece'));
        $this->expectException(DatabaseRefusal::class);
        $change->owed($receipt, 'missing');
    }
}
