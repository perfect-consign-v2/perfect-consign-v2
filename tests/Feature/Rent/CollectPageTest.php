<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Rent;

use PerfectConsign\Shared\Http\View;
use PHPUnit\Framework\TestCase;

final class CollectPageTest extends TestCase
{
    public function testOneFormAddsUpTheWaysTheyPaid(): void
    {
        $html = $this->page(0, 49666, 0, true, '', '', '', '', 'C-1', [
            ['label' => 'Rent', 'date' => '2026-09-20', 'amount' => 14666, 'journal_id' => ''],
            ['label' => 'Paid', 'date' => '2026-09-20', 'amount' => -5000, 'journal_id' => ''],
            ['label' => 'Rent', 'date' => '2026-10-01', 'amount' => 40000, 'journal_id' => ''],
        ]);

        self::assertStringContainsString('>They owe<', $html);
        self::assertStringContainsString('$496.66', $html);
        self::assertStringContainsString('09-20-2026', $html);
        self::assertStringContainsString('$146.66', $html);
        self::assertStringContainsString('($50.00)', $html);
        self::assertStringContainsString('10-01-2026', $html);
        self::assertStringContainsString('$400.00', $html);
        self::assertStringNotContainsString('>Waive<', $html);
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
        $html = $this->page(2500, 49666, 47166, true, '', '', '', '', 'C-1', [
            ['label' => 'Rent', 'date' => '2026-10-01', 'amount' => 47166, 'journal_id' => ''],
            ['label' => 'Late fee', 'date' => '2026-10-05', 'amount' => 2500, 'journal_id' => '00000000-0000-7000-8000-000000000099'],
        ]);

        self::assertStringContainsString('>Rent <', $html);
        self::assertStringContainsString('$471.66', $html);
        self::assertStringContainsString('>Waive late fee <', $html);
        self::assertStringContainsString('10-05-2026', $html);
        self::assertStringContainsString('$25.00', $html);
        self::assertStringContainsString('$496.66', $html);
        self::assertStringContainsString('name="waive[]" value="00000000-0000-7000-8000-000000000099"', $html);
        self::assertStringContainsString('data-cents="2500"', $html);
        self::assertStringContainsString('name="waive_note" maxlength="500"', $html);
        self::assertStringContainsString('id="waive-note-box" hidden', $html);
        self::assertStringContainsString('>Waive note<', $html);
        self::assertStringNotContainsString('>Waive</button>', $html);
        self::assertStringNotContainsString('Waive all late fees', $html);
        self::assertStringNotContainsString('action="/mall/rent/waive"', $html);
        self::assertSame(1, substr_count($html, '<form '));
        self::assertSame(1, substr_count($html, '>Collect</button>'));
    }

    public function testEveryLateFeeCanBeWaivedTogether(): void
    {
        $html = $this->page(3500, 3500, 0, true, '', '', '', '', 'C-1', [
            ['label' => 'Late fee', 'date' => '2026-09-01', 'amount' => 2500, 'journal_id' => '00000000-0000-7000-8000-000000000099'],
            ['label' => 'Late fee', 'date' => '2026-10-01', 'amount' => 1000, 'journal_id' => '00000000-0000-7000-8000-000000000098'],
        ]);

        self::assertSame(2, substr_count($html, 'type="checkbox" name="waive[]"'));
        self::assertSame(2, substr_count($html, '>Waive late fee <'));
        self::assertStringContainsString('id="waive-all"', $html);
        self::assertStringContainsString('Waive all late fees', $html);
        self::assertStringContainsString('name="waive_note" maxlength="500"', $html);
        self::assertStringNotContainsString('Waive every late fee?', $html);
        self::assertStringNotContainsString('action="/mall/rent/waive"', $html);
        self::assertSame(1, substr_count($html, '<form '));
        self::assertSame(1, substr_count($html, '>Collect</button>'));
    }

    public function testARentBalanceWithoutALateFeeDoesNotRepeatRent(): void
    {
        $html = $this->page(0, 4000, 4000);

        self::assertStringContainsString('<span>C-1</span>', $html);
        self::assertStringContainsString('>Rent <', $html);
        self::assertStringNotContainsString('>Late fee <', $html);
        self::assertStringNotContainsString('>Waive</button>', $html);
    }

    public function testAnEmptyBoothIsShownAsADash(): void
    {
        $html = $this->page(0, 4000, 4000, true, '', '', '', '', '');

        self::assertStringContainsString('<span>—</span>', $html);
        self::assertStringNotContainsString('<span>C-1</span>', $html);
    }

    public function testABalanceThatIsOnlyALateFeeDoesNotRepeatRent(): void
    {
        $html = $this->page(2500, 2500, 0, true, '', '', '', '', 'C-1', [
            ['label' => 'Late fee', 'date' => '2026-10-05', 'amount' => 2500, 'journal_id' => '00000000-0000-7000-8000-000000000099'],
        ]);

        self::assertStringContainsString('>Waive late fee <', $html);
        self::assertStringContainsString('name="waive[]"', $html);
        self::assertStringNotContainsString('>Waive</button>', $html);
        self::assertStringNotContainsString('>Rent <', $html);
    }

    public function testAClosedRegisterDoesNotAskForMoney(): void
    {
        $html = $this->page(0, 4000, 4000, false);

        self::assertStringContainsString('Open a register to collect', $html);
        self::assertStringNotContainsString('Cash they handed you', $html);
        self::assertStringNotContainsString('name="pay_with"', $html);
        self::assertStringNotContainsString('<form ', $html);
    }

    public function testAClosedRegisterCanStillWaiveAFee(): void
    {
        $html = $this->page(2500, 2500, 0, false, '', '', '', '', 'C-1', [
            ['label' => 'Late fee', 'date' => '2026-10-05', 'amount' => 2500, 'journal_id' => '00000000-0000-7000-8000-000000000099'],
        ]);

        self::assertStringContainsString('Open a register to collect', $html);
        self::assertStringNotContainsString('name="pay_with"', $html);
        self::assertStringContainsString('name="waive[]" value="00000000-0000-7000-8000-000000000099"', $html);
        self::assertStringContainsString('name="waive_note" maxlength="500"', $html);
        self::assertStringContainsString('>Waive late fees</button>', $html);
        self::assertSame(1, substr_count($html, '<form '));
        // checked( filled( needsDrawer( figurePrepaid( figureOpen(
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

    public function testAZeroLineIsPlainAndAClosedRegisterDoesNotOfferAWaive(): void
    {
        $html = $this->page(0, 0, 0, true, '', '', '', '', 'C-1', [
            ['label' => 'Rent', 'date' => '2026-10-01', 'amount' => 0, 'journal_id' => 'fee-1'],
        ]);
        $closed = $this->page(0, 0, 0, false);

        self::assertStringContainsString('$0.00', $html);
        self::assertStringNotContainsString('($0.00)', $html);
        self::assertStringNotContainsString('type="checkbox" name="waive[]"', $html);
        self::assertStringNotContainsString('id="waive-note"', $closed);
        self::assertStringNotContainsString('Waive late fees', $closed);
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
        ?array $lines = null,
    ): string {
        if ($lines === null) {
            $lines = [
                ['label' => 'Rent', 'date' => '2026-10-01', 'amount' => $owed, 'journal_id' => ''],
            ];
        }
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
            'lines' => $lines,
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
