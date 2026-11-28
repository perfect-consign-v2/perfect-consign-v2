<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Book;

use InvalidArgumentException;
use PerfectConsign\Feature\Book\MissedSale;
use PerfectConsign\Ledger\PostingFunctions;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Tests\Support\QuietAudit;
use PHPUnit\Framework\TestCase;

final class MissedSaleTest extends TestCase
{
    private const VENDOR = '01900000-0000-7000-8000-0000000000v1';

    private const JOURNAL = '01900000-0000-7000-8000-0000000000j1';

    private const TOKEN = '0123456789abcdef0123456789abcdef';

    public function testTheVendorShareComesFromTheDatabase(): void
    {
        $statements = $this->createMock(Statements::class);
        $statements->expects(self::once())->method('bound')->with(
            'SELECT stall_missed_sale_quote(?::uuid, ?, ?) AS share',
            [self::VENDOR, 'Ada-1', 10000],
        )->willReturn([['share' => '6000']]);

        self::assertSame(6000, $this->sale($statements)->vendorShare(self::VENDOR, 'Ada-1', 10000));
    }

    public function testAQuoteWithoutAShareIsRefused(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([]);
        $this->expectException(InvalidArgumentException::class);
        $this->sale($statements)->vendorShare(self::VENDOR, '', 1000);
    }

    public function testRecordAsksTheDatabaseToPostAndAuditsANewJournal(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([['fresh' => 'true']]);
        $audits = 0;
        $statements->method('affected')->willReturnCallback(static function () use (&$audits): int {
            $audits++;

            return 1;
        });

        self::assertSame(self::JOURNAL, $this->record($statements));
        self::assertSame(1, $audits);
    }

    public function testARepeatedPressReturnsTheJournalWithoutASecondAudit(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([['fresh' => 'false']]);
        $audits = 0;
        $statements->method('affected')->willReturnCallback(static function () use (&$audits): int {
            $audits++;

            return 1;
        });

        self::assertSame(self::JOURNAL, $this->record($statements));
        self::assertSame(0, $audits);
    }

    public function testVendorsAreListed(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('rows')->willReturn([
            ['id' => self::VENDOR, 'name' => 'Ada'],
        ]);

        self::assertSame(
            [['id' => self::VENDOR, 'name' => 'Ada']],
            $this->sale($statements)->vendors(),
        );
        self::assertSame([], $this->sale($this->rows([]))->vendors());
    }

    public function testAVendorRowWithoutANameIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->sale($this->rows([['id' => self::VENDOR]]))->vendors();
    }

    private function record(Statements $statements): string
    {
        $postings = $this->createMock(PostingFunctions::class);
        $postings->expects(self::once())->method('post')->willReturnCallback(
            static function (
                string $date,
                string $call,
                array $values,
                ?string $session = null,
                ?string $check = null,
                ?int $tendered = null,
                ?callable $beforeCommit = null,
            ): string {
                self::assertSame('2026-06-15', $date);
                self::assertSame('stall_post_missed_sale(?::uuid, ?, ?, ?::date, ?, ?, ?)', $call);
                self::assertSame([self::VENDOR, 'Ada-1', 1000, '2026-06-15', 'Lamp', 'walked out', self::TOKEN], $values);
                self::assertNull($session);
                self::assertNotNull($beforeCommit);
                $beforeCommit(self::JOURNAL);

                return self::JOURNAL;
            },
        );

        return (new MissedSale($postings, $statements, QuietAudit::owner($statements)))->record(
            self::VENDOR,
            'Ada-1',
            1000,
            '2026-06-15',
            'Lamp',
            'walked out',
            self::TOKEN,
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function rows(array $rows): Statements
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('rows')->willReturn($rows);

        return $statements;
    }

    private function sale(Statements $statements): MissedSale
    {
        return new MissedSale($this->createStub(PostingFunctions::class), $statements, QuietAudit::owner($statements));
    }
}
