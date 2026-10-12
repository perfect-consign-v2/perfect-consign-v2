<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Rent;

use PerfectConsign\Feature\Rent\RentPayment;
use PHPUnit\Framework\TestCase;

final class RentPaymentTest extends TestCase
{
    private RentPayment $payment;

    protected function setUp(): void
    {
        $this->payment = new RentPayment();
    }

    public function testCashTurnsExtraBillsIntoChange(): void
    {
        // cash( card( check( split( dollars( minor(
        $paid = $this->payment->apply($this->tender('cash', tendered: '50.00'), '', 4800);

        self::assertSame(4800, $paid['minor']);
        self::assertSame('48.00', $paid['tender']['cash']);
        self::assertSame('50.00', $paid['tender']['tendered']);
        self::assertSame('0.00', $paid['tender']['card']);
        self::assertSame('0.00', $paid['tender']['checks']);
        self::assertSame('', $paid['tender']['check']);
    }

    public function testCashLessThanTheBalanceIsAPartialPayment(): void
    {
        $paid = $this->payment->apply($this->tender('cash', tendered: '20.00'), '', 4800);

        self::assertSame(2000, $paid['minor']);
        self::assertSame('20.00', $paid['tender']['cash']);
        self::assertSame('20.00', $paid['tender']['tendered']);
    }

    public function testCashUsesTheCashBoxWhenNothingWasHandedOver(): void
    {
        $paid = $this->payment->apply($this->tender('cash', cash: '12.50'), '', 4800);

        self::assertSame(1250, $paid['minor']);
        self::assertSame('12.50', $paid['tender']['tendered']);
    }

    public function testABlankCashPaymentIsZero(): void
    {
        $paid = $this->payment->apply($this->tender('cash'), '48.00', 4800);

        self::assertSame(0, $paid['minor']);
        self::assertSame('0.00', $paid['tender']['cash']);
        self::assertSame('0.00', $paid['tender']['tendered']);
    }

    public function testACardIsTheAmountKeyed(): void
    {
        $paid = $this->payment->apply($this->tender('card', card: '10.5'), '', 4800);

        self::assertSame(1050, $paid['minor']);
        self::assertSame('10.50', $paid['tender']['card']);
        self::assertSame('0.00', $paid['tender']['cash']);
        self::assertSame('', $paid['tender']['tendered']);
    }

    public function testACardAboveTheBalanceIsNotCutDown(): void
    {
        $paid = $this->payment->apply($this->tender('card', card: '100.00'), '', 4800);

        self::assertSame(10000, $paid['minor']);
    }

    public function testACheckKeepsItsNumber(): void
    {
        $paid = $this->payment->apply($this->tender('check', checks: '4.00', check: ' 1001 '), '', 4800);

        self::assertSame(400, $paid['minor']);
        self::assertSame('4.00', $paid['tender']['checks']);
        self::assertSame('1001', $paid['tender']['check']);
        self::assertSame('0.00', $paid['tender']['cash']);
    }

    public function testASplitIsTheSumOfTheWaysTheyPaid(): void
    {
        $paid = $this->payment->apply(
            $this->tender('split', cash: '1.00', card: '2.00', tendered: '5.00'),
            '99.00',
            4800,
        );

        self::assertSame(300, $paid['minor']);
        self::assertSame('1.00', $paid['tender']['cash']);
        self::assertSame('2.00', $paid['tender']['card']);
        self::assertSame('0.00', $paid['tender']['checks']);
        self::assertSame('5.00', $paid['tender']['tendered']);
        self::assertSame('', $paid['tender']['check']);
    }

    public function testCashBillsStopAtWhatIsStillOwed(): void
    {
        $paid = $this->payment->apply($this->tender('split', cash: '50.00'), '', 4800);

        self::assertSame(4800, $paid['minor']);
        self::assertSame('48.00', $paid['tender']['cash']);
        self::assertSame('50.00', $paid['tender']['tendered']);
        self::assertSame('0.00', $paid['tender']['card']);
    }

    public function testCashBillsUnderTheBalanceAreTheWholePayment(): void
    {
        $paid = $this->payment->apply($this->tender('split', cash: '20.00', card: '10.00'), '', 4800);

        self::assertSame(3000, $paid['minor']);
        self::assertSame('20.00', $paid['tender']['cash']);
        self::assertSame('', $paid['tender']['tendered']);
        self::assertSame('10.00', $paid['tender']['card']);
    }

    public function testExtraCashAfterACardIsChange(): void
    {
        $paid = $this->payment->apply($this->tender('split', cash: '50.00', card: '10.00'), '', 4800);

        self::assertSame(4800, $paid['minor']);
        self::assertSame('38.00', $paid['tender']['cash']);
        self::assertSame('50.00', $paid['tender']['tendered']);
        self::assertSame('10.00', $paid['tender']['card']);
    }

    public function testACardAboveTheBalanceIsNotCoveredByCashChange(): void
    {
        $paid = $this->payment->apply($this->tender('split', cash: '20.00', card: '100.00'), '', 4800);

        self::assertSame(10000, $paid['minor']);
        self::assertSame('0.00', $paid['tender']['cash']);
        self::assertSame('', $paid['tender']['tendered']);
    }

    public function testABlankWayStillUsesTheAmountField(): void
    {
        $paid = $this->payment->apply($this->tender('', cash: '10.00', tendered: '20.00'), '5.00', 4800);

        self::assertSame(500, $paid['minor']);
        self::assertSame('10.00', $paid['tender']['cash']);
        self::assertSame('20.00', $paid['tender']['tendered']);
    }

    public function testALateFeeStaysInWhatTheyOweAfterRent(): void
    {
        self::assertSame(['rent' => 4800, 'late' => 0], $this->payment->parts(4800, 0));
        self::assertSame(['rent' => 4800, 'late' => 500], $this->payment->parts(5300, 500));
        self::assertSame(['rent' => 0, 'late' => 1000], $this->payment->parts(1000, 5000));
        self::assertSame(['rent' => 0, 'late' => 0], $this->payment->parts(0, 500));
    }

    /**
     * @return array{cash: string, card: string, checks: string, tendered: string, check: string, way: string}
     */
    private function tender(
        string $way,
        string $cash = '',
        string $card = '',
        string $checks = '',
        string $tendered = '',
        string $check = '',
    ): array {
        return [
            'way' => $way,
            'cash' => $cash,
            'card' => $card,
            'checks' => $checks,
            'tendered' => $tendered,
            'check' => $check,
        ];
    }
}
