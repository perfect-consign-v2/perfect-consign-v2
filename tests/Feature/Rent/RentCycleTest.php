<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Rent;

use InvalidArgumentException;
use PerfectConsign\Feature\Payout\PayoutWindow;
use PerfectConsign\Feature\Rent\RentCycle;
use PHPUnit\Framework\TestCase;

final class RentCycleTest extends TestCase
{
    // refuseClosedRentals( chrome( rentPolicy( rentalsEnabled( cycleDay( cycleFrozen(
    // getCompanyLines( isRentalsEnabled( getCycleDay( staysByBooth( availableOn( endsLater(
    // nextDay( requireDate( optionalDay( periodLabel(
    // away( rentHome( optional( place( profile( writeContact( rename( writeName(
    // shift( ordinal( uncovered( covered( moves(
    public function testDayOneMatchesACalendarMonth(): void
    {
        $cycle = new RentCycle(1);

        self::assertSame(
            ['from' => '2026-02-01', 'to' => '2026-02-28', 'rent' => '2026-03-01'],
            $cycle->payout('2026-03-15')
        );
        self::assertSame(
            ['from' => '2024-02-01', 'to' => '2024-02-29', 'rent' => '2024-03-01'],
            (new PayoutWindow())->window('2024-03-10')
        );
        self::assertSame('2026-03', $cycle->suffix($cycle->start('2026-03-20')));
        self::assertSame(['2026-03'], $cycle->suffixesTouching('2026-03'));
    }

    public function testDayFifteenRunsFromTheFifteenthThroughTheFourteenth(): void
    {
        $cycle = new RentCycle(15);

        self::assertSame('2026-03-15', $cycle->start('2026-03-20'));
        self::assertSame('2026-04-14', $cycle->end('2026-03-15'));
        self::assertSame('2026-03-15', $cycle->suffix('2026-03-15'));
        self::assertSame(['2026-02-15', '2026-03-15'], $cycle->suffixesTouching('2026-03'));
        self::assertSame(
            ['from' => '2026-02-15', 'to' => '2026-03-14', 'rent' => '2026-03-15'],
            $cycle->payout('2026-03-20')
        );
        self::assertStringContainsString(
            'Rent starts on the 15th. A period runs the 15th through the 14th.',
            $cycle->sentence(true)
        );
        self::assertStringContainsString(
            'The previous period\'s sales pay this period\'s rent.',
            $cycle->sentence(true)
        );
    }

    public function testOrdinalsNameTheFirstSecondAndThird(): void
    {
        self::assertStringContainsString(
            'Rent starts on the 1st. A period is a calendar month.',
            (new RentCycle(1))->sentence(false)
        );
        self::assertStringContainsString(
            'Rent starts on the 2nd. A period runs the 2nd through the 1st.',
            (new RentCycle(2))->sentence(false)
        );
        self::assertStringContainsString(
            'Rent starts on the 3rd. A period runs the 3rd through the 2nd.',
            (new RentCycle(3))->sentence(false)
        );
        self::assertStringContainsString(
            'Rent starts on the 22nd. A period runs the 22nd through the 21st.',
            (new RentCycle(22))->sentence(false)
        );
        self::assertStringContainsString('Rent starts on the 11th.', (new RentCycle(11))->sentence(false));
        self::assertStringContainsString('Pay vendors their sales.', (new RentCycle(11))->sentence(false));
        self::assertStringContainsString('the 13th through the 12th', (new RentCycle(13))->sentence(false));
    }

    public function testDayTwentyEightIsTheLastCycleDay(): void
    {
        $cycle = new RentCycle(28);

        self::assertSame(28, $cycle->day());
        self::assertSame('2026-02-28', $cycle->start('2026-02-28'));
        self::assertSame(['2026-01-28', '2026-02-28'], $cycle->suffixesTouching('2026-02'));
    }

    public function testThePeriodOpensOnItsFirstDay(): void
    {
        $cycle = new RentCycle(15);

        self::assertSame('2026-03-15', $cycle->start('2026-03-15'));
        $window = new PayoutWindow();
        self::assertSame($window->window('2026-03-10', 1), $window->window('2026-03-10', 0));
        self::assertSame($window->window('2026-03-10', 1), $window->window('2026-03-10', 29));
        self::assertNotSame($window->window('2026-03-10', 1), $window->window('2026-03-10', 28));
    }

    public function testACycleDayOutsideOneThroughTwentyEightIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the rent cycle day is a whole number from 1 to 28');
        new RentCycle(0);
    }

    public function testDayTwentyNineIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RentCycle(29);
    }
}
