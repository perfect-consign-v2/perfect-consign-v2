<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Rent;

use PerfectConsign\Feature\Rent\LateFee;
use PerfectConsign\Ledger\DatabaseRefusal;
use PerfectConsign\Ledger\LedgerStore;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;

final class LateFeeBoundaryTest extends TestCase
{
    private const BOOTH = '01900000-0000-7000-8000-0000000000b1';

    private const JOURNAL = '01900000-0000-7000-8000-0000000000c1';

    public function testACentIsPostedAndAnExistingFeeIsKept(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([]);
        $ledger = $this->createMock(LedgerStore::class);
        $ledger->method('roleAccount')->willReturn('account-1');
        $ledger->expects($this->once())->method('post')->willReturn('journal-1');
        $fee = new LateFee($ledger, $statements);

        self::assertSame('journal-1', $fee->post(self::BOOTH, '2026-10', '2026-10-16', 1));
    }

    public function testAnExistingFeeIsNotPostedAgain(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([['id' => 'already']]);
        $ledger = $this->createMock(LedgerStore::class);
        $ledger->expects($this->never())->method('post');

        self::assertSame('already', (new LateFee($ledger, $statements))->post(self::BOOTH, '2026-10', '2026-10-16', 100));
    }

    public function testABlankExistingIdStillPosts(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([['id' => '']]);
        $ledger = $this->createMock(LedgerStore::class);
        $ledger->method('roleAccount')->willReturn('account-1');
        $ledger->expects($this->once())->method('post')->willReturn('journal-1');

        self::assertSame('journal-1', (new LateFee($ledger, $statements))->post(self::BOOTH, '2026-10', '2026-10-16', 100));
    }

    public function testARentJournalIsNotALateFee(): void
    {
        $fee = $this->waiver(['source_type' => 'rent_charge', 'is_reversal' => false], false);

        $this->expectException(DatabaseRefusal::class);
        $this->expectExceptionMessage('Choose a late fee.');
        $fee->waive(self::JOURNAL, '2026-10-16');
    }

    public function testAReversalFlagBlocksAnotherWaive(): void
    {
        $fee = $this->waiver(['source_type' => 'late_fee', 'is_reversal' => true], false);

        $this->expectException(DatabaseRefusal::class);
        $this->expectExceptionMessage('That late fee is already waived.');
        $fee->waive(self::JOURNAL, '2026-10-16');
    }

    public function testAFiveHundredCharacterNoteIsStored(): void
    {
        $ledger = $this->createMock(LedgerStore::class);
        $ledger->method('journal')->willReturn([
            'source_type' => 'late_fee',
            'is_reversal' => false,
            'posting_date' => '2026-10-16',
            'source_reference' => self::BOOTH . ':2026-10',
        ]);
        $ledger->method('reversed')->willReturn(false);
        $ledger->method('lines')->willReturn([
            ['account_id' => 'a', 'credit_minor' => 1, 'debit_minor' => 0, 'subledger_type' => 'booth', 'subledger_ref' => self::BOOTH],
            ['account_id' => 'b', 'credit_minor' => 0, 'debit_minor' => 1, 'subledger_type' => null, 'subledger_ref' => null],
        ]);
        $ledger->expects($this->once())->method('post')->willReturn('reversal-1');
        $fee = new LateFee($ledger, $this->createStub(Statements::class));

        self::assertSame('reversal-1', $fee->waive(self::JOURNAL, '2026-10-16', str_repeat('n', 500)));

        $long = $this->waiver(['source_type' => 'late_fee', 'is_reversal' => false], false);
        try {
            $long->waive(self::JOURNAL, '2026-10-16', str_repeat('n', 501));
            self::fail('a long waive note was stored');
        } catch (DatabaseRefusal $error) {
            self::assertSame('Enter a shorter waive note.', $error->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $journal
     */
    private function waiver(array $journal, bool $reversed): LateFee
    {
        $journal += [
            'posting_date' => '2026-10-16',
            'source_reference' => self::BOOTH . ':2026-10',
        ];
        $ledger = $this->createStub(LedgerStore::class);
        $ledger->method('journal')->willReturn($journal);
        $ledger->method('reversed')->willReturn($reversed);

        return new LateFee($ledger, $this->createStub(Statements::class));
    }
}
