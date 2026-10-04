<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Rent;

use InvalidArgumentException;
use PerfectConsign\Feature\Rent\RentBook;
use PerfectConsign\Feature\Rent\RentLines;
use PHPUnit\Framework\TestCase;

final class RentBookTest extends TestCase
{
    private const ADA = '01900000-0000-7000-8000-0000000000a1';

    private const BEA = '01900000-0000-7000-8000-0000000000a2';

    private const NORTH = '01900000-0000-7000-8000-0000000000b1';

    private const SOUTH = '01900000-0000-7000-8000-0000000000b2';

    private const EAST = '01900000-0000-7000-8000-0000000000b3';

    private const JOURNAL = '01900000-0000-7000-8000-0000000000c1';

    public function testAnUnchargedRateIsStillToCharge(): void
    {
        $book = $this->book()->assemble(
            $this->facts(
                [$this->booth(self::NORTH, 'A10', 'North')],
                [$this->stay(self::NORTH, self::ADA, 'Ada', '2026-10-01', null)],
                [$this->rate(self::NORTH, 4000)],
                [],
                []
            ),
            '2026-10',
            '2026-10-03'
        );

        self::assertSame('October 2026', $book['label']);
        self::assertSame('2026-09', $book['previous']);
        self::assertSame('2026-11', $book['next']);
        self::assertSame(4000, $book['stats']['due_minor'], 'stats( sums an uncharged rate');
        self::assertTrue($book['rows'][0]['chargeable'], 'posted( keeps a stay that covers the charge date');
        self::assertSame(1, $book['stats']['due_count'], 'present( names the holder and the rate');
        self::assertSame(0, $book['stats']['charged_count']);
        self::assertSame(0, $book['stats']['owed_minor']);
        self::assertSame(0, $book['stats']['owed_count']);
        self::assertFalse($book['rows'][0]['charged']);
        self::assertSame(4000, $book['rows'][0]['rate_minor']);
        self::assertSame('2026-10-03', $book['rows'][0]['charge_date']);
        self::assertSame(self::ADA, $book['rows'][0]['vendor_id']);
        self::assertSame('Ada', $book['rows'][0]['vendor_name']);
        self::assertSame('', $book['rows'][0]['collect_amount']);
    }

    public function testAChargedMonthStaysCharged(): void
    {
        $book = $this->book()->assemble(
            $this->facts(
                [$this->booth(self::NORTH, 'A1', 'North')],
                [$this->stay(self::NORTH, self::ADA, 'Ada', '2020-01-01', null)],
                [$this->rate(self::NORTH, 10000)],
                [$this->charge(self::NORTH, '2026-06', 10000, false)],
                [['booth_id' => self::NORTH, 'open_minor' => 10000]]
            ),
            '2026-06',
            '2026-10-03'
        );

        self::assertSame('June 2026', $book['label']);
        self::assertSame(0, $book['stats']['due_count']);
        self::assertSame(10000, $book['stats']['charged_minor'], 'charges( keeps the live journal');
        self::assertSame(1, $book['stats']['charged_count']);
        self::assertSame(10000, $book['stats']['owed_minor']);
        self::assertTrue($book['rows'][0]['charged']);
        self::assertSame(self::JOURNAL, $book['rows'][0]['journal_id']);
        self::assertSame(10000, $book['rows'][0]['charge_minor']);
        self::assertSame('2026-06-01', $book['rows'][0]['charge_date']);
        self::assertSame('100.00', $book['rows'][0]['collect_amount']);
    }

    public function testAReversedChargeIsStillToCharge(): void
    {
        $book = $this->book()->assemble(
            $this->facts(
                [$this->booth(self::NORTH, 'A1', 'North')],
                [$this->stay(self::NORTH, self::ADA, 'Ada', '2026-10-01', null)],
                [$this->rate(self::NORTH, 4000)],
                [
                    $this->charge(self::NORTH, '2026-10', 4000, true),
                    $this->charge(self::NORTH, '2026-09', 4000, false),
                ],
                []
            ),
            '2026-10',
            '2026-10-03'
        );

        self::assertFalse($book['rows'][0]['charged']);
        self::assertNull($book['rows'][0]['journal_id']);
        self::assertSame(1, $book['stats']['due_count'], 'chargeBooth( ignores a reversed charge');
        self::assertSame(4000, $book['stats']['due_minor']);
        self::assertSame(0, $book['stats']['charged_count']);
    }

    public function testOpenBalancePrefillsCollect(): void
    {
        $book = $this->book()->assemble(
            $this->facts(
                [
                    $this->booth(self::EAST, 'C1', 'East'),
                    $this->booth(self::NORTH, 'A1', 'North'),
                ],
                [],
                [],
                [],
                [
                    ['booth_id' => self::EAST, 'open_minor' => 10050],
                    ['booth_id' => self::NORTH, 'open_minor' => 0],
                ]
            ),
            '2026-10',
            '2026-10-03'
        );

        self::assertSame(['C1'], array_column($book['rows'], 'code'));
        self::assertNull($book['rows'][0]['vendor_id']);
        self::assertNull($book['rows'][0]['rate_minor']);
        self::assertSame(10050, $book['rows'][0]['open_minor']);
        self::assertSame('100.50', $book['rows'][0]['collect_amount']);
        self::assertSame(10050, $book['stats']['owed_minor'], 'openBalances( skips a zero balance');
        self::assertSame(1, $book['stats']['owed_count']);
    }

    public function testChargeDateFollowsTheMonth(): void
    {
        $book = $this->book()->assemble(
            $this->facts(
                [
                    $this->booth(self::SOUTH, 'A2', 'South'),
                    $this->booth(self::NORTH, 'A10', 'North'),
                ],
                [
                    $this->stay(self::NORTH, self::ADA, 'Ada', '2026-06-15', null),
                    $this->stay(self::SOUTH, self::BEA, 'Bea', '2020-01-01', null),
                ],
                [
                    $this->rate(self::NORTH, 9000),
                    $this->rate(self::SOUTH, 8000),
                ],
                [],
                []
            ),
            '2026-06',
            '2026-10-03'
        );

        $byCode = [];
        foreach ($book['rows'] as $row) {
            $byCode[$row['code']] = $row;
        }
        self::assertSame(['A2', 'A10'], array_column($book['rows'], 'code'));
        self::assertSame('2026-06-15', $byCode['A10']['charge_date'], 'posted( chargeDate( uses the assignment start');
        self::assertSame(4800, $byCode['A10']['rate_minor'], 'charge( prorates the move-in month');
        self::assertTrue($byCode['A10']['chargeable']);
        self::assertSame('2026-06-01', $byCode['A2']['charge_date'], 'day( reads YYYY-MM-DD');
        self::assertSame(8000, $byCode['A2']['rate_minor']);
        self::assertSame(12800, $book['stats']['due_minor']);
    }

    public function testAnOverrideIsWhatGetsCharged(): void
    {
        $book = $this->book()->assemble(
            $this->facts(
                [$this->booth(self::NORTH, 'A1', 'North')],
                [$this->stay(self::NORTH, self::ADA, 'Ada', '2026-03-15', null, 6200)],
                [$this->rate(self::NORTH, 3100)],
                [],
                []
            ),
            '2026-03',
            '2026-10-03'
        );

        self::assertSame(3400, $book['rows'][0]['rate_minor'], 'override( replaces the booth rate before the move-in days');
        self::assertSame(3400, $book['stats']['due_minor']);
        self::assertTrue($book['rows'][0]['chargeable']);
    }

    public function testAStayThatStartsLaterIsNotChargedYet(): void
    {
        $book = $this->book()->assemble(
            $this->facts(
                [$this->booth(self::NORTH, 'A1', 'North')],
                [$this->stay(self::NORTH, self::ADA, 'Ada', '2026-10-20', null)],
                [$this->rate(self::NORTH, 4000)],
                [],
                []
            ),
            '2026-10',
            '2026-10-03'
        );

        self::assertSame('Ada', $book['rows'][0]['vendor_name']);
        self::assertFalse($book['rows'][0]['chargeable'], 'posted( waits until the stay covers the charge date');
        self::assertSame(0, $book['stats']['due_count']);
        self::assertSame(4000, $book['rows'][0]['rate_minor']);
    }

    public function testTheLaterAssignmentHoldsTheBooth(): void
    {
        $book = $this->book()->assemble(
            $this->facts(
                [$this->booth(self::NORTH, 'A1', 'North')],
                [
                    $this->stay(self::NORTH, self::ADA, 'Ada', '2026-06-01', null),
                    $this->stay(self::NORTH, self::BEA, 'Bea', '2026-06-15', '2026-06-30'),
                ],
                [$this->rate(self::NORTH, 1000)],
                [],
                []
            ),
            '2026-06',
            '2026-10-03'
        );

        self::assertSame(self::BEA, $book['rows'][0]['vendor_id'], 'holder( keeps the later start');
        self::assertSame('Bea', $book['rows'][0]['vendor_name'], 'stays( reads both assignments');
        self::assertSame('2026-06-15', $book['rows'][0]['charge_date'], 'endDate( keeps an end inside the month');
    }

    public function testSearchAndStandingNarrowTheBook(): void
    {
        $book = $this->book()->assemble(
            $this->facts(
                [
                    $this->booth(self::NORTH, 'A10', 'North'),
                    $this->booth(self::SOUTH, 'A2', 'South'),
                ],
                [
                    $this->stay(self::NORTH, self::ADA, 'Ada', '2026-10-01', null),
                    $this->stay(self::SOUTH, self::BEA, 'Bea', '2026-10-01', null),
                ],
                [
                    $this->rate(self::NORTH, 1000),
                    $this->rate(self::SOUTH, 2000),
                ],
                [$this->charge(self::SOUTH, '2026-10', 2000, false)],
                [['booth_id' => self::NORTH, 'open_minor' => 1000]]
            ),
            '2026-10',
            '2026-10-03'
        );
        $ada = $this->book()->narrow($book['rows'], 'ADA', '');
        self::assertSame(['A10'], array_column($ada['rows'], 'code'), 'matches( finds the vendor');
        self::assertSame(0, $ada['counts']['charged'], 'counts( leaves an uncharged booth out of the charged tally');
        self::assertSame(1, $ada['matched']);
        $due = $this->book()->narrow($book['rows'], '', 'due');
        self::assertSame(['A10'], array_column($due['rows'], 'code'), 'picked( keeps the booth still to charge');
        self::assertSame('due', $due['standing'], 'standing( keeps a filter that has rows');
        self::assertSame(1, $due['counts']['due'], 'counts( tallies the searched rows');
        self::assertSame(1, $due['counts']['charged']);
        self::assertSame(1, $due['counts']['owed']);
        $charged = $this->book()->narrow($book['rows'], '', 'charged');
        self::assertSame(['A2'], array_column($charged['rows'], 'code'), 'kept( drops a charged booth from the due filter');
        $owed = $this->book()->narrow($book['rows'], 'bea', 'owed');
        self::assertSame('', $owed['standing']);
        self::assertSame(['A2'], array_column($owed['rows'], 'code'));
        $none = $this->book()->narrow($book['rows'], 'nope', 'due');
        self::assertSame([], $none['rows']);
        self::assertSame(0, $none['matched']);
        self::assertSame('', $none['standing']);
    }

    public function testABadMonthFallsBackToTheCurrentMonth(): void
    {
        $book = $this->book();

        self::assertSame('2026-10', $book->month('nope', '2026-10-03'));
        self::assertSame('2026-10', $book->month('2026-13', '2026-10-03'));
        self::assertSame('2026-06', $book->month('2026-06', '2026-10-03'));
    }

    public function testARentMonthAndDateMustBeReal(): void
    {
        $lines = new RentLines();
        $rows = $lines->build(
            $this->facts([$this->booth(self::NORTH, 'A1', 'North')], [], [$this->rate(self::NORTH, 1000)], [], []),
            '2026-10',
            '2026-10-03'
        );
        self::assertSame('A1', $rows[0]['code']);
        try {
            $this->book()->assemble($this->facts([], [], [], [], []), '2026-13', '2026-10-03');
            self::fail('requireMonth( accepts only YYYY-MM');
        } catch (InvalidArgumentException $error) {
            self::assertSame('a rent month is YYYY-MM', $error->getMessage());
        }
        try {
            $this->book()->month('2026-06', 'yesterday');
            self::fail('requireDay( rejects a date that is not YYYY-MM-DD');
        } catch (InvalidArgumentException $error) {
            self::assertSame('a rent date is YYYY-MM-DD', $error->getMessage());
        }
        try {
            $lines->build(
                $this->facts(
                    [$this->booth(self::NORTH, 'A1', 'North')],
                    [$this->stay(self::NORTH, self::ADA, 'Ada', 'not-a-date', null)],
                    [$this->rate(self::NORTH, 1000)],
                    [],
                    []
                ),
                '2026-10',
                '2026-10-03'
            );
            self::fail('day( rejects a date that is not YYYY-MM-DD');
        } catch (InvalidArgumentException $error) {
            self::assertSame('a rent date is YYYY-MM-DD', $error->getMessage());
        }
    }

    public function testVacantBoothsAndOutsideAssignmentsStayOffTheBook(): void
    {
        $book = $this->book()->assemble(
            $this->facts(
                [
                    $this->booth(self::NORTH, 'A1', 'North'),
                    $this->booth(self::SOUTH, 'A2', 'South'),
                    $this->booth(self::EAST, 'A3', 'East'),
                ],
                [
                    $this->stay(self::NORTH, self::ADA, 'Ada', '2026-01-01', '2026-05-31'),
                    $this->stay(self::SOUTH, self::BEA, 'Bea', '2026-07-01', null),
                ],
                [$this->rate(self::SOUTH, 5000)],
                [],
                []
            ),
            '2026-06',
            '2026-10-03'
        );

        self::assertSame(['A2'], array_column($book['rows'], 'code'), 'blank( leaves a vacant booth off the book');
        self::assertNull($book['rows'][0]['vendor_id']);
        self::assertFalse($book['rows'][0]['chargeable'], 'posted( coversStay( skips a rated booth with no holder');
        self::assertSame(0, $book['stats']['due_count']);
        self::assertSame(5000, $book['rows'][0]['rate_minor'], 'rates( uses the amount stored for that month');
    }

    public function testAOneCentBalanceAndANegativeBalanceStayDistinct(): void
    {
        $book = $this->book()->assemble(
            $this->facts(
                [
                    $this->booth(self::NORTH, 'A1', 'North'),
                    $this->booth(self::SOUTH, 'A2', 'South'),
                ],
                [],
                [$this->rate(self::SOUTH, 5000)],
                [],
                [
                    ['booth_id' => self::NORTH, 'open_minor' => 1],
                    ['booth_id' => self::SOUTH, 'open_minor' => 2500],
                    ['booth_id' => self::SOUTH, 'open_minor' => 0],
                    ['booth_id' => self::SOUTH, 'open_minor' => -50],
                ]
            ),
            '2026-06',
            '2026-10-03'
        );
        $byCode = [];
        foreach ($book['rows'] as $row) {
            $byCode[$row['code']] = $row;
        }

        self::assertSame(['A1', 'A2'], array_column($book['rows'], 'code'));
        self::assertSame(1, $byCode['A1']['open_minor'], 'blank( keeps a one-cent balance on the book');
        self::assertSame(2500, $byCode['A2']['open_minor'], 'openBalances( keeps an open balance when a later row is not positive');
        self::assertSame(2, $book['stats']['owed_count']);
        self::assertSame(2501, $book['stats']['owed_minor']);
    }

    public function testTheSameStartDateKeepsTheLaterAssignment(): void
    {
        $book = $this->book()->assemble(
            $this->facts(
                [$this->booth(self::NORTH, 'A1', 'North')],
                [
                    $this->stay(self::NORTH, self::ADA, 'Ada', '2026-06-01', null),
                    $this->stay(self::NORTH, self::BEA, 'Bea', '2026-06-01', null),
                ],
                [$this->rate(self::NORTH, 1000)],
                [],
                []
            ),
            '2026-06',
            '2026-10-03'
        );

        self::assertSame(self::BEA, $book['rows'][0]['vendor_id'], 'holder( replaces an assignment with the same start');
    }

    public function testAStayOnTheMonthBoundaryStillHoldsTheBooth(): void
    {
        $book = $this->book()->assemble(
            $this->facts(
                [
                    $this->booth(self::NORTH, 'A1', 'North'),
                    $this->booth(self::SOUTH, 'A2', 'South'),
                ],
                [
                    $this->stay(self::NORTH, self::ADA, 'Ada', '2026-06-30', null),
                    $this->stay(self::SOUTH, self::BEA, 'Bea', '2026-01-01', '2026-06-01'),
                ],
                [],
                [],
                []
            ),
            '2026-06',
            '2026-10-03'
        );
        $byCode = [];
        foreach ($book['rows'] as $row) {
            $byCode[$row['code']] = $row;
        }

        self::assertSame(self::ADA, $byCode['A1']['vendor_id'], 'covers( keeps a stay that starts on the last day');
        self::assertSame(self::BEA, $byCode['A2']['vendor_id'], 'covers( keeps a stay that ends on the first day');
    }

    private function book(): RentBook
    {
        return new RentBook(new RentLines());
    }

    /**
     * @param list<array<string, mixed>> $booths
     * @param list<array<string, mixed>> $assignments
     * @param list<array<string, mixed>> $rates
     * @param list<array<string, mixed>> $charges
     * @param list<array<string, mixed>> $openRent
     * @return array{
     *   booths: list<array<string, mixed>>,
     *   assignments: list<array<string, mixed>>,
     *   rates: list<array<string, mixed>>,
     *   charges: list<array<string, mixed>>,
     *   open_rent: list<array<string, mixed>>
     * }
     */
    private function facts(array $booths, array $assignments, array $rates, array $charges, array $openRent): array
    {
        return [
            'booths' => $booths,
            'assignments' => $assignments,
            'rates' => $rates,
            'charges' => $charges,
            'open_rent' => $openRent,
        ];
    }

    /**
     * @return array{id: string, code: string, name: string}
     */
    private function booth(string $id, string $code, string $name): array
    {
        return ['id' => $id, 'code' => $code, 'name' => $name];
    }

    /**
     * @return array{booth_id: string, vendor_id: string, vendor_name: string, starts_on: string, ends_on: ?string, override_minor: ?int}
     */
    private function stay(string $boothId, string $vendorId, string $name, string $starts, ?string $ends, ?int $override = null): array
    {
        return [
            'booth_id' => $boothId,
            'vendor_id' => $vendorId,
            'vendor_name' => $name,
            'starts_on' => $starts,
            'ends_on' => $ends,
            'override_minor' => $override,
        ];
    }

    /**
     * @return array{booth_id: string, amount_minor: int}
     */
    private function rate(string $boothId, int $amount): array
    {
        return ['booth_id' => $boothId, 'amount_minor' => $amount];
    }

    /**
     * @return array{journal_id: string, posting_key: string, debit_total_minor: int, reversed: bool}
     */
    private function charge(string $boothId, string $month, int $amount, bool $reversed): array
    {
        return [
            'journal_id' => self::JOURNAL,
            'posting_key' => 'rent-charge:' . $boothId . ':' . $month,
            'debit_total_minor' => $amount,
            'reversed' => $reversed,
        ];
    }
}
