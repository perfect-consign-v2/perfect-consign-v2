<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Payout;

use PerfectConsign\Feature\Payout\VendorClawback;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Tests\Feature\Coverage\MallDoor;
use PHPUnit\Framework\TestCase;

final class VendorClawbackGapTest extends TestCase
{
    public function testAZeroReclassDoesNotPost(): void
    {
        $posts = 0;
        $clawback = $this->clawback(12, 9, $posts);

        self::assertSame('', $clawback->reclass(MallDoor::VENDOR, 0, '2026-11-12', MallDoor::RUN));
        self::assertSame(0, $posts);
    }

    public function testReclassAndApplyPostWhenBothSidesAreOpen(): void
    {
        $posts = 0;
        $clawback = $this->clawback(12, 9, $posts);

        self::assertSame(MallDoor::JOURNAL, $clawback->reclass(MallDoor::VENDOR, 4, '2026-11-12', MallDoor::RUN));
        self::assertSame(MallDoor::JOURNAL, $clawback->apply(MallDoor::VENDOR, '2026-11-12', MallDoor::RUN));
        self::assertSame(2, $posts);
    }

    public function testApplySkipsWhenNothingIsOpen(): void
    {
        $posts = 0;
        $clawback = $this->clawback(0, 0, $posts);

        self::assertSame('', $clawback->apply(MallDoor::VENDOR, '2026-11-12', MallDoor::RUN));
        self::assertSame(0, $posts);
    }

    private function clawback(int $payable, int $receivable, int &$posts): VendorClawback
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturnCallback(
            static function (string $sql) use ($payable, $receivable): array {
                if (str_contains($sql, 'credit_minor - line.debit_minor')) {
                    return [['total' => $payable]];
                }

                return [['total' => $receivable]];
            }
        );
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('roleAccount')->willReturn(MallDoor::ACCOUNT);
        $ledger->method('post')->willReturnCallback(static function () use (&$posts): string {
            $posts++;

            return MallDoor::JOURNAL;
        });

        return new VendorClawback($ledger, $statements);
    }
}
