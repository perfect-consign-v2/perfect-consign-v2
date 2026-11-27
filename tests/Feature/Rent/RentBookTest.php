<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Rent;

use InvalidArgumentException;
use PerfectConsign\Feature\Rent\RentBook;
use PHPUnit\Framework\TestCase;

/**
 * The rent book on screen: totals and filters over the database's rows. Which
 * rows exist, their amounts, dates and charges are proved in prove_rent_book.sql.
 */
final class RentBookTest extends TestCase
{
    private const MARY = '01900000-0000-7000-8000-0000000000a1';

    private const JOE = '01900000-0000-7000-8000-0000000000a2';

    private const BOOTH = '01900000-0000-7000-8000-0000000000b1';

    private const EMPTY = '01900000-0000-7000-8000-0000000000b2';

    public function testEachStayIsItsOwnRowAndTheTotalsFollowThem(): void
    {
        $book = (new RentBook())->assemble($this->rows(), '2026-03', '2026-03-20');

        self::assertSame('March 2026', $book['label']);
        self::assertSame('2026-02', $book['previous']);
        self::assertSame('2026-04', $book['next']);
        self::assertCount(4, $book['rows']);
        self::assertSame([
            'due_minor' => 2709,
            'due_count' => 1,
            'charged_minor' => 6000,
            'charged_count' => 1,
            'owed_minor' => 6000 + 100,
            'owed_count' => 2,
        ], $book['stats']);
    }

    public function testAStayOnTwoPeriodsIsOwedOnce(): void
    {
        $rows = $this->rows();
        $second = $rows[0];
        $second['id'] = self::MARY . ':2026-03-15';
        $second['period_start'] = '2026-03-15';
        $rows[] = $second;

        $book = (new RentBook())->assemble($rows, '2026-03', '2026-03-20');

        self::assertSame(2, $book['stats']['owed_count']);
        self::assertSame(6100, $book['stats']['owed_minor']);
    }

    public function testSearchAndStandingNarrowTheBook(): void
    {
        $book = new RentBook();
        $rows = $this->rows();

        $joe = $book->narrow($rows, 'JOE', '');
        self::assertSame(1, $joe['matched']);
        self::assertSame('Joe Brown', $joe['rows'][0]['vendor_name']);

        $booth = $book->narrow($rows, '12', 'due');
        self::assertSame(3, $booth['matched']);
        self::assertSame('due', $booth['standing']);
        self::assertCount(1, $booth['rows']);
        self::assertSame(['due' => 1, 'charged' => 1, 'owed' => 2], $booth['counts']);

        $charged = $book->narrow($rows, '', 'charged');
        self::assertCount(1, $charged['rows']);
        self::assertSame(self::MARY, $charged['rows'][0]['assignment_id']);

        $owed = $book->narrow($rows, '', 'owed');
        self::assertCount(2, $owed['rows']);

        $unknown = $book->narrow($rows, '', 'everything');
        self::assertSame('', $unknown['standing']);
        self::assertCount(4, $unknown['rows']);

        $none = $book->narrow($rows, 'nobody', 'due');
        self::assertSame('', $none['standing']);
        self::assertSame([], $none['rows']);
    }

    public function testABadMonthFallsBackToTheCurrentMonth(): void
    {
        $book = new RentBook();

        self::assertSame('2026-03', $book->month('', '2026-03-20'));
        self::assertSame('2026-03', $book->month('2026-13', '2026-03-20'));
        self::assertSame('2026-01', $book->month('2026-01', '2026-03-20'));
    }

    public function testARentMonthAndDateMustBeReal(): void
    {
        $book = new RentBook();
        try {
            $book->assemble([], 'March', '2026-03-20');
            self::fail('a rent month that is not a month was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('a rent month is YYYY-MM', $error->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a rent date is YYYY-MM-DD');
        $book->month('2026-03', 'today');
    }

    /**
     * @return list<array{
     *   id: string, assignment_id: ?string, booth_id: string, code: string, name: string,
     *   vendor_id: ?string, vendor_name: ?string, period_start: string, period_label: string,
     *   rate_minor: ?int, charged: bool, chargeable: bool, charge_minor: ?int, journal_id: ?string,
     *   open_minor: int, charge_date: string, collect_amount: string
     * }>
     */
    private function rows(): array
    {
        return [
            $this->row(self::MARY, 'Mary Smith', 6000, true, false, 6000, 6000),
            $this->row(self::JOE, 'Joe Brown', 2709, false, true, null, 0),
            $this->row('01900000-0000-7000-8000-0000000000a3', 'Pat Penny', 100, false, false, null, 100),
            [
                'id' => self::EMPTY,
                'assignment_id' => null,
                'booth_id' => self::EMPTY,
                'code' => '20',
                'name' => 'Booth 20',
                'vendor_id' => null,
                'vendor_name' => null,
                'period_start' => '2026-03-01',
                'period_label' => 'March 2026',
                'rate_minor' => 4500,
                'charged' => false,
                'chargeable' => false,
                'charge_minor' => null,
                'journal_id' => null,
                'open_minor' => 0,
                'charge_date' => '',
                'collect_amount' => '',
            ],
        ];
    }

    /**
     * @return array{
     *   id: string, assignment_id: ?string, booth_id: string, code: string, name: string,
     *   vendor_id: ?string, vendor_name: ?string, period_start: string, period_label: string,
     *   rate_minor: ?int, charged: bool, chargeable: bool, charge_minor: ?int, journal_id: ?string,
     *   open_minor: int, charge_date: string, collect_amount: string
     * }
     */
    private function row(string $stay, string $vendor, int $quote, bool $charged, bool $chargeable, ?int $charge, int $open): array
    {
        return [
            'id' => $stay . ':2026-03-01',
            'assignment_id' => $stay,
            'booth_id' => self::BOOTH,
            'code' => '12',
            'name' => 'Booth 12',
            'vendor_id' => $stay,
            'vendor_name' => $vendor,
            'period_start' => '2026-03-01',
            'period_label' => 'March 2026',
            'rate_minor' => $quote,
            'charged' => $charged,
            'chargeable' => $chargeable,
            'charge_minor' => $charge,
            'journal_id' => $charged ? '01900000-0000-7000-8000-0000000000c1' : null,
            'open_minor' => $open,
            'charge_date' => '2026-03-01',
            'collect_amount' => $open > 0 ? number_format($open / 100, 2, '.', '') : '',
        ];
    }
}
