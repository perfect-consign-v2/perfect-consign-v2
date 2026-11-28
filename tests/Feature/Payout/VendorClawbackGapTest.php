<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Payout;

use PerfectConsign\Feature\Payout\VendorClawback;
use PerfectConsign\Ledger\PostingFunctions;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Tests\Feature\Coverage\MallDoor;
use PHPUnit\Framework\TestCase;

final class VendorClawbackGapTest extends TestCase
{
    public function testTheBalancesComeFromTheDatabase(): void
    {
        $clawback = new VendorClawback($this->createStub(PostingFunctions::class), $this->balances(12, 9, 9));

        self::assertSame(12, $clawback->openPayable(MallDoor::VENDOR));
        self::assertSame(9, $clawback->openReceivable(MallDoor::VENDOR));
    }

    public function testBeforeAVoidTheDatabasePostsWhatIsOwedBack(): void
    {
        $postings = $this->createMock(PostingFunctions::class);
        $postings->expects(self::once())->method('postBatch')->with(
            ['2026-11-12'],
            'stall_clawback_reclass(?::uuid, ?::date)',
            [MallDoor::JOURNAL, '2026-11-12'],
        )->willReturn(2);

        self::assertSame(2, (new VendorClawback($postings, $this->balances(0, 0, 0)))->beforeVoid(MallDoor::JOURNAL, '2026-11-12'));
    }

    public function testApplyPostsWhenTheDatabaseSaysSomethingIsDue(): void
    {
        $postings = $this->createMock(PostingFunctions::class);
        $postings->expects(self::once())->method('post')->with(
            '2026-11-12',
            'stall_clawback_apply(?::uuid, ?, ?::date)',
            [MallDoor::VENDOR, MallDoor::RUN, '2026-11-12'],
        )->willReturn(MallDoor::JOURNAL);

        self::assertSame(MallDoor::JOURNAL, (new VendorClawback($postings, $this->balances(12, 9, 9)))->apply(MallDoor::VENDOR, '2026-11-12', MallDoor::RUN));
    }

    public function testApplySkipsWhenNothingIsDue(): void
    {
        $postings = $this->createMock(PostingFunctions::class);
        $postings->expects(self::never())->method('post');

        self::assertSame('', (new VendorClawback($postings, $this->balances(0, 0, 0)))->apply(MallDoor::VENDOR, '2026-11-12', MallDoor::RUN));
    }

    private function balances(int $payable, int $receivable, int $due): Statements
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturnCallback(
            static function (string $sql) use ($payable, $receivable, $due): array {
                if (str_contains($sql, 'stall_vendor_open_payable')) {
                    return [['total' => $payable]];
                }
                if (str_contains($sql, 'stall_vendor_open_clawback')) {
                    return [['total' => $receivable]];
                }

                return [['total' => $due]];
            }
        );

        return $statements;
    }
}
