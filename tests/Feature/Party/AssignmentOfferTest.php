<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Party;

use PerfectConsign\Feature\Party\AssignmentOffer;
use PerfectConsign\Feature\Party\PartyStore;
use PHPUnit\Framework\TestCase;

final class AssignmentOfferTest extends TestCase
{
    private const NORTH = '01900000-0000-7000-8000-0000000000b1';

    private const SOUTH = '01900000-0000-7000-8000-0000000000b2';

    public function testAnOpenStayIsLeftOut(): void
    {
        $rows = $this->offer(
            [$this->booth(self::NORTH, 'A12')],
            [$this->stay(self::NORTH, '2020-01-01', null)],
        )->booths('2026-10-15');

        self::assertSame([], $rows);
    }

    public function testADatedStayShowsTheNextDay(): void
    {
        $rows = $this->offer(
            [$this->booth(self::NORTH, 'A12')],
            [$this->stay(self::NORTH, '2020-01-01', '2026-10-15')],
        )->booths('2026-10-15');

        self::assertSame([
            $this->choice(self::NORTH, 'A12', '2026-10-16'),
        ], $rows);
    }

    public function testJoinedStaysFreeTheDayAfterTheLastEnd(): void
    {
        $rows = $this->offer(
            [$this->booth(self::NORTH, 'A12')],
            [
                $this->stay(self::NORTH, '2020-01-01', '2026-10-15'),
                $this->stay(self::NORTH, '2026-10-16', '2026-10-20'),
            ],
        )->booths('2026-10-15');

        self::assertSame([
            $this->choice(self::NORTH, 'A12', '2026-10-21'),
        ], $rows);
    }

    public function testAJoinedOpenStayIsLeftOut(): void
    {
        $rows = $this->offer(
            [$this->booth(self::NORTH, 'A12')],
            [
                $this->stay(self::NORTH, '2020-01-01', '2026-10-15'),
                $this->stay(self::NORTH, '2026-10-16', null),
            ],
        )->booths('2026-10-15');

        self::assertSame([], $rows);
    }

    public function testAGapAfterTheCurrentStayIsTheFreeDate(): void
    {
        $rows = $this->offer(
            [$this->booth(self::NORTH, 'A12')],
            [
                $this->stay(self::NORTH, '2020-01-01', '2026-10-15'),
                $this->stay(self::NORTH, '2026-11-01', '2026-11-05'),
            ],
        )->booths('2026-10-15');

        self::assertSame([
            $this->choice(self::NORTH, 'A12', '2026-10-16'),
        ], $rows);
    }

    public function testAFreeBoothAndAnIncomingStayStayOpenToday(): void
    {
        $rows = $this->offer(
            [
                $this->booth(self::NORTH, 'A12'),
                $this->booth(self::SOUTH, 'B2'),
            ],
            [
                $this->stay(self::NORTH, '2020-01-01', '2026-10-01'),
                $this->stay(self::SOUTH, '2026-11-01', null),
            ],
        )->booths('2026-10-15');

        self::assertSame([
            $this->choice(self::NORTH, 'A12', ''),
            $this->choice(self::SOUTH, 'B2', ''),
        ], $rows);
    }

    public function testAnOpenStayWinsOverADatedStayOnTheSameDays(): void
    {
        $rows = $this->offer(
            [
                $this->booth(self::NORTH, 'A12'),
                $this->booth(self::SOUTH, 'B2'),
            ],
            [
                $this->stay(self::NORTH, '2020-01-01', '2026-12-31'),
                $this->stay(self::NORTH, '2020-01-01', null),
                $this->stay(self::SOUTH, '2020-01-01', null),
                $this->stay(self::SOUTH, '2020-01-01', '2026-12-31'),
            ],
        )->booths('2026-10-15');

        self::assertSame([], $rows);
    }

    public function testABoothDateThatIsNotADayIsRefused(): void
    {
        $offer = $this->offer([$this->booth(self::NORTH, 'A12')], []);
        try {
            $offer->booths('October');
            self::fail('today was not a date');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('today is YYYY-MM-DD', $error->getMessage());
        }
        try {
            $this->offer(
                [['code' => 'A12']],
                [],
            )->booths('2026-10-15');
            self::fail('a booth without an id was listed');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('a booth field is missing', $error->getMessage());
        }
        try {
            $this->offer(
                [$this->booth(self::NORTH, 'A12')],
                [['booth_id' => self::NORTH, 'starts_on' => null, 'ends_on' => null]],
            )->booths('2026-10-15');
            self::fail('a stay without a start was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('a booth date is YYYY-MM-DD', $error->getMessage());
        }
        try {
            $this->offer(
                [$this->booth(self::NORTH, 'A12')],
                [['booth_id' => self::NORTH, 'starts_on' => '2026-01-01', 'ends_on' => 12]],
            )->booths('2026-10-15');
            self::fail('a stay end that is not a date was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('a booth date is YYYY-MM-DD', $error->getMessage());
        }
        try {
            $this->offer(
                [$this->booth(self::NORTH, 'A12')],
                [$this->stay(self::NORTH, '2026-02-01', '2026-02-31')],
            )->booths('2026-02-15');
            self::fail('February 31 was accepted');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('a booth date is YYYY-MM-DD', $error->getMessage());
        }
    }

    /**
     * @param list<array<string, mixed>> $booths
     * @param list<array<string, mixed>> $stays
     */
    private function offer(array $booths, array $stays): AssignmentOffer
    {
        $store = $this->createStub(PartyStore::class);
        $store->method('booths')->willReturn($booths);
        $store->method('assignments')->willReturn($stays);
        $store->method('askingRent')->willReturn(10000);

        return new AssignmentOffer($store);
    }

    /**
     * @return array{id: string, code: string}
     */
    private function booth(string $id, string $code): array
    {
        return ['id' => $id, 'code' => $code];
    }

    /**
     * @return array{booth_id: string, starts_on: string, ends_on: ?string}
     */
    private function stay(string $boothId, string $startsOn, ?string $endsOn): array
    {
        return ['booth_id' => $boothId, 'starts_on' => $startsOn, 'ends_on' => $endsOn];
    }

    /**
     * @return array{id: string, code: string, rent: string, available_on: string}
     */
    private function choice(string $id, string $code, string $availableOn): array
    {
        return [
            'id' => $id,
            'code' => $code,
            'rent' => '$100.00',
            'available_on' => $availableOn,
        ];
    }
}
