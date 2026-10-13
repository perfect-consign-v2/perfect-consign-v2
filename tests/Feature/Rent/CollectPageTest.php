<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Rent;

use PerfectConsign\Shared\Http\View;
use PHPUnit\Framework\TestCase;

final class CollectPageTest extends TestCase
{
    public function testOneFormAddsUpTheWaysTheyPaid(): void
    {
        $html = $this->page(0, 49666);

        self::assertStringContainsString('>They owe<', $html);
        self::assertStringContainsString('$496.66', $html);
        self::assertStringContainsString('They are paying', $html);
        self::assertStringContainsString('id="tender-cash" name="tender_cash" inputmode="decimal" value=""', $html);
        self::assertStringContainsString('id="tender-card" name="tender_card" inputmode="decimal" value=""', $html);
        self::assertStringContainsString('id="tender-checks" name="tender_checks" inputmode="decimal" value=""', $html);
        self::assertStringContainsString('value="split"', $html);
        self::assertStringNotContainsString('Cash they handed you', $html);
        self::assertStringNotContainsString('name="cash_tendered"', $html);
        self::assertStringNotContainsString('name="amount"', $html);
        self::assertStringNotContainsString('Amount received', $html);
        self::assertStringNotContainsString('<span>Rent</span>', $html);
        self::assertStringNotContainsString('<span>Late fee</span>', $html);
        self::assertStringContainsString('<span>C-1</span>', $html);
        self::assertSame(1, substr_count($html, '<form '));
        self::assertSame(1, substr_count($html, '>Collect</button>'));
        self::assertStringContainsString('id="rent-check-box" hidden', $html);
    }

    public function testALateFeeIsPartOfWhatTheyOwe(): void
    {
        $html = $this->page(2500, 49666, 47166);

        self::assertStringContainsString('<span>Rent</span>', $html);
        self::assertStringContainsString('$471.66', $html);
        self::assertStringContainsString('<span>Late fee</span>', $html);
        self::assertStringContainsString('$25.00', $html);
        self::assertStringContainsString('$496.66', $html);
    }

    public function testARentBalanceWithoutALateFeeDoesNotRepeatRent(): void
    {
        $html = $this->page(0, 4000, 4000);

        self::assertStringContainsString('<span>C-1</span>', $html);
        self::assertStringNotContainsString('<span>Rent</span>', $html);
        self::assertStringNotContainsString('<span>Late fee</span>', $html);
    }

    public function testAnEmptyBoothIsShownAsADash(): void
    {
        $html = $this->page(0, 4000, 4000, true, '', '', '', '', '');

        self::assertStringContainsString('<span>—</span>', $html);
        self::assertStringNotContainsString('<span>C-1</span>', $html);
    }

    public function testABalanceThatIsOnlyALateFeeDoesNotRepeatRent(): void
    {
        $html = $this->page(2500, 2500, 0);

        self::assertStringContainsString('<span>Late fee</span>', $html);
        self::assertStringNotContainsString('<span>Rent</span>', $html);
    }

    public function testAClosedRegisterDoesNotAskForMoney(): void
    {
        $html = $this->page(0, 4000, 4000, false);

        self::assertStringContainsString('Open a register to collect', $html);
        self::assertStringNotContainsString('Cash they handed you', $html);
        self::assertStringNotContainsString('name="pay_with"', $html);
    }

    public function testAFailedPaymentKeepsTheAmountsTheyTyped(): void
    {
        $html = $this->page(0, 4000, 4000, true, '10.00', '5.00', '1.00', '44');

        self::assertStringContainsString('id="tender-cash" name="tender_cash" inputmode="decimal" value="10.00"', $html);
        self::assertStringContainsString('id="tender-card" name="tender_card" inputmode="decimal" value="5.00"', $html);
        self::assertStringContainsString('id="tender-checks" name="tender_checks" inputmode="decimal" value="1.00"', $html);
        self::assertStringContainsString('id="check-number" name="check_number" maxlength="40" value="44"', $html);
        self::assertStringNotContainsString('id="rent-check-box" hidden', $html);
        self::assertStringNotContainsString('name="cash_tendered"', $html);
    }

    public function testACardOnlyPaymentHidesTheCheckNumber(): void
    {
        $html = $this->page(0, 4000, 4000, true, '', '12.00');

        self::assertStringContainsString('id="rent-check-box" hidden', $html);
        self::assertStringContainsString('id="tender-cash" name="tender_cash" inputmode="decimal" value=""', $html);
    }

    private function page(
        int $late,
        int $owed,
        int $rent = 0,
        bool $open = true,
        string $tenderCash = '',
        string $tenderCard = '',
        string $tenderChecks = '',
        string $checkNumber = '',
        string $booth = 'C-1',
    ): string {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Feature/Rent/collect', [
            'slug' => 'mall',
            'csrf' => 'token',
            'figure' => [
                'vendor_id' => 'vendor-1',
                'vendor_name' => 'Gina Holt',
                'booth_id' => 'booth-1',
                'booth' => $booth,
                'owed' => $owed,
                'rent' => $rent,
                'late' => $late,
                'collect' => '0.00',
            ],
            'tenderCash' => $tenderCash,
            'tenderCard' => $tenderCard,
            'tenderChecks' => $tenderChecks,
            'checkNumber' => $checkNumber,
            'openRegister' => $open,
        ]);
        $html = ob_get_clean();
        self::assertIsString($html);

        return $html;
    }
}
