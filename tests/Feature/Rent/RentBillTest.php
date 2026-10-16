<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Rent;

use PerfectConsign\Feature\Rent\RentBill;
use PHPUnit\Framework\TestCase;

final class RentBillTest extends TestCase
{
    public function testOpenRentShowsTheChargeAndWhatWasAlreadyPaid(): void
    {
        $lines = (new RentBill())->explain([
            $this->move('rent-sept', 'rent_charge', '2026-09-20', 'C-1', 14666, 0),
            $this->move('pay-sept', 'rent_payment', '2026-09-20', 'C-1', 0, 5000),
            $this->move('rent-oct', 'rent_charge', '2026-10-01', 'C-1', 40000, 0),
        ], 49666);

        self::assertSame([
            ['label' => 'Rent', 'date' => '2026-09-20', 'amount' => 14666, 'journal_id' => ''],
            ['label' => 'Paid', 'date' => '2026-09-20', 'amount' => -5000, 'journal_id' => ''],
            ['label' => 'Rent', 'date' => '2026-10-01', 'amount' => 40000, 'journal_id' => ''],
        ], $lines);
    }

    public function testAPaidMonthDropsOffTheBill(): void
    {
        $lines = (new RentBill())->explain([
            $this->move('rent-aug', 'rent_charge', '2026-08-01', 'A-1', 30000, 0),
            $this->move('settle-aug', 'settlement', '2026-08-01', 'A-1', 0, 30000),
            $this->move('rent-oct', 'rent_charge', '2026-10-01', 'A-1', 30000, 0),
        ], 30000);

        self::assertSame([
            ['label' => 'Rent', 'date' => '2026-10-01', 'amount' => 30000, 'journal_id' => ''],
        ], $lines);
    }

    public function testAPaymentComesOffRentBeforeAnOlderLateFee(): void
    {
        $lines = (new RentBill())->explain([
            $this->move('fee-1', 'late_fee', '2026-09-01', 'C-1', 2500, 0),
            $this->move('rent-oct', 'rent_charge', '2026-10-01', 'C-1', 40000, 0),
            $this->move('pay', 'rent_payment', '2026-10-02', 'C-1', 0, 40000),
        ], 2500);

        self::assertSame([
            ['label' => 'Late fee', 'date' => '2026-09-01', 'amount' => 2500, 'journal_id' => 'fee-1'],
        ], $lines);
    }

    public function testAPartlyPaidLateFeeStaysOnTheBillAndCannotBeWaived(): void
    {
        $lines = (new RentBill())->explain([
            $this->move('rent-oct', 'rent_charge', '2026-10-01', 'C-1', 10000, 0),
            $this->move('fee-1', 'late_fee', '2026-10-05', 'C-1', 2500, 0),
            $this->move('pay', 'rent_payment', '2026-10-06', 'C-1', 0, 11000),
        ], 1500);

        self::assertSame([
            ['label' => 'Late fee', 'date' => '2026-10-05', 'amount' => 2500, 'journal_id' => ''],
            ['label' => 'Paid', 'date' => '2026-10-06', 'amount' => -1000, 'journal_id' => ''],
        ], $lines);
    }

    public function testSalesThatCoverPartOfTheRentAreTheirOwnLine(): void
    {
        // take( accepts( chargeLines( moves( waiveChosen( swapped( total(
        $lines = (new RentBill())->explain([
            $this->move('rent-sep', 'rent_charge', '2026-09-01', 'D-1', 15000, 0),
            $this->move('rent-oct', 'rent_charge', '2026-10-01', 'D-1', 15000, 0),
        ], 20000);

        self::assertSame([
            ['label' => 'Rent', 'date' => '2026-09-01', 'amount' => 15000, 'journal_id' => ''],
            ['label' => 'Rent', 'date' => '2026-10-01', 'amount' => 15000, 'journal_id' => ''],
            ['label' => 'From what they earned', 'date' => '', 'amount' => -10000, 'journal_id' => ''],
        ], $lines);
    }

    public function testTwoBoothsAreNamedOnTheLine(): void
    {
        $lines = (new RentBill())->explain([
            $this->move('rent-a', 'rent_charge', '2026-10-01', 'C-1', 10000, 0),
            $this->move('rent-b', 'rent_charge', '2026-10-01', 'C-2', 20000, 0),
        ], 30000);

        self::assertSame('Rent C-1', $lines[0]['label']);
        self::assertSame('Rent C-2', $lines[1]['label']);
    }

    public function testAPaymentClearsTheOldestRentAcrossBoothsBeforeLateFees(): void
    {
        $shares = (new RentBill())->share([
            $this->move('rent-1', 'rent_charge', '2026-08-01', 'A-1', 15000, 0, 'booth-a'),
            $this->move('fee-1', 'late_fee', '2026-09-01', 'A-1', 2500, 0, 'booth-a'),
            $this->move('rent-2', 'rent_charge', '2026-10-01', 'A-2', 30000, 0, 'booth-b'),
        ], 20000);

        self::assertSame([
            ['booth_id' => 'booth-a', 'amount' => 15000],
            ['booth_id' => 'booth-b', 'amount' => 5000],
        ], $shares);
    }

    public function testACentStillComesOffTheBooth(): void
    {
        $charge = $this->move('rent', 'rent_charge', '2026-10-01', 'C-1', 1, 0, 'booth-1');
        $lines = (new RentBill())->explain([$charge], 1);
        self::assertSame([
            ['label' => 'Rent', 'date' => '2026-10-01', 'amount' => 1, 'journal_id' => ''],
        ], $lines);

        $paid = (new RentBill())->explain([
            $charge,
            $this->move('pay', 'rent_payment', '2026-10-02', 'C-1', 0, 1, 'booth-1'),
        ], 0);
        self::assertSame([], $paid);

        $loose = (new RentBill())->explain([
            $this->move('pay', 'rent_payment', '2026-10-02', 'C-1', 0, 1, 'booth-1'),
        ], -1);
        self::assertSame([
            ['label' => 'Paid', 'date' => '2026-10-02', 'amount' => -1, 'journal_id' => ''],
        ], $loose);

        self::assertSame(
            [['booth_id' => 'booth-1', 'amount' => 1]],
            (new RentBill())->share([$charge], 1)
        );
        self::assertSame(
            [],
            (new RentBill())->share([$this->move('rent', 'rent_charge', '2026-10-01', 'C-1', 100, 0, '')], 50)
        );
    }

    public function testRentThatIsNotChargedYetIsStillALine(): void
    {
        $lines = (new RentBill())->explain([], 40000);

        self::assertSame([
            ['label' => 'Rent', 'date' => '', 'amount' => 40000, 'journal_id' => ''],
        ], $lines);
    }

    /**
     * @return array{journal_id: string, source: string, date: string, booth: string, booth_id: string, debit: int, credit: int}
     */
    private function move(string $id, string $source, string $date, string $booth, int $debit, int $credit, string $boothId = ''): array
    {
        return [
            'journal_id' => $id,
            'source' => $source,
            'date' => $date,
            'booth' => $booth,
            'booth_id' => $boothId,
            'debit' => $debit,
            'credit' => $credit,
        ];
    }
}
