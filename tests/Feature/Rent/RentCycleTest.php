<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Rent;

use InvalidArgumentException;
use PerfectConsign\Feature\Payout\PayoutWindow;
use PerfectConsign\Feature\Rent\RentCycle;
use PerfectConsign\Feature\Rent\RentFigure;
use PerfectConsign\Feature\Rent\RentLines;
use PerfectConsign\Feature\Rent\RentSpan;
use PHPUnit\Framework\TestCase;

final class RentCycleTest extends TestCase
{
    // refuseClosedRentals( chrome( rentPolicy( rentalsEnabled( cycleDay( cycleFrozen(
    // getCompanyLines( isRentalsEnabled( getCycleDay( staysByBooth( availableOn( endsLater(
    // nextDay( requireDate( optionalDay( openPeriods( dueLines( periodLabel( chargeFacts(
    // away( rentHome( optional( place( profile( writeContact( rename( writeName(
    // shift( ordinal( cover( uncovered( unposted( covered( moves(
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
        self::assertSame(3400, $cycle->amount(6200, '2026-03-15', '2026-03-20'));
    }

    public function testDayFifteenRunsFromTheFifteenthThroughTheFourteenth(): void
    {
        $cycle = new RentCycle(15);

        self::assertSame('2026-03-15', $cycle->start('2026-03-20'));
        self::assertSame('2026-04-14', $cycle->end('2026-03-15'));
        self::assertSame('2026-03-15', $cycle->suffix('2026-03-15'));
        self::assertSame(2600, $cycle->amount(3100, '2026-03-20', '2026-03-20'));
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

    public function testAPinnedPeriodUsesThatPeriodOnTheRentBook(): void
    {
        try {
            (new RentLines())->build($this->emptyFacts(), '2026-03', '2026-03-20', 15, '2026-03-15');
            self::fail('a booth without a name was listed');
        } catch (InvalidArgumentException $error) {
            self::assertSame('a rent field is missing', $error->getMessage());
        }
        $moved = RentFigure::posted(3100, '2026-03-20', null, '2026-03', '2026-04-01', 15, '2026-03-15');
        self::assertSame('2026-03-20', $moved['date']);
        self::assertSame(2600, $moved['amount']);
        $later = RentFigure::posted(3100, '2026-05-01', null, '2026-03', '2026-04-01', 15, '2026-03-15');
        self::assertSame('2026-03-15', $later['date']);
    }

    /**
     * @return array{
     *   booths: list<array<string, mixed>>,
     *   assignments: list<array<string, mixed>>,
     *   rates: list<array<string, mixed>>,
     *   charges: list<array<string, mixed>>,
     *   open_rent: list<array<string, mixed>>
     * }
     */
    private function emptyFacts(): array
    {
        return [
            'booths' => [['id' => '']],
            'assignments' => [],
            'rates' => [],
            'charges' => [],
            'open_rent' => [],
        ];
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
        self::assertSame(3100, $cycle->amount(3100, '2026-03-01', '2026-03-20'));
        self::assertSame(100, $cycle->amount(3100, '2026-04-14', '2026-03-20'));
        $onEnd = RentFigure::posted(3100, '2026-04-14', null, '2026-03', '2026-04-01', 15, '2026-03-15');
        self::assertSame('2026-04-14', $onEnd['date']);
        $day = RentSpan::cover('2026-02', '2026-03-10', 28, null);
        self::assertSame(28, $day->day);
        self::assertSame(1, RentSpan::cover('2026-02', '2026-03-10', 0, null)->day);
        self::assertSame(1, RentSpan::cover('2026-02', '2026-03-10', 29, null)->day);
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
