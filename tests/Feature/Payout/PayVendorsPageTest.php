<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Payout;

use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Web\ListTable;
use PHPUnit\Framework\TestCase;

final class PayVendorsPageTest extends TestCase
{
    protected function tearDown(): void
    {
        $needed = new \ReflectionProperty(ListTable::class, 'needed');
        $printed = new \ReflectionProperty(ListTable::class, 'printed');
        $needed->setValue(null, false);
        $printed->setValue(null, false);
    }

    public function testAnOpenMonthAsksHowToPay(): void
    {
        $html = $this->page('', '', '');

        self::assertStringContainsString('Pay these vendors', $html);
        self::assertStringContainsString('Pay from', $html);
        self::assertStringContainsString('Cash from the drawer', $html);
        self::assertStringContainsString('Cash from the safe', $html);
        $earned = strpos($html, 'dt-column-title">They earned<');
        $rent = strpos($html, 'dt-column-title">Rent<');
        $payThem = strpos($html, 'dt-column-title">Pay them<');
        self::assertNotFalse($earned);
        self::assertNotFalse($rent);
        self::assertNotFalse($payThem);
        self::assertLessThan($rent, $earned);
        self::assertLessThan($payThem, $rent);
        self::assertStringContainsString('Rent comes out of what they earned.', $html);
        self::assertStringNotContainsString('First check number', $html);
        self::assertStringNotContainsString('dt-column-title">Their share<', $html);
        self::assertStringNotContainsString('dt-column-title">Owed before<', $html);
        self::assertStringNotContainsString('dt-column-title">This month<', $html);
        self::assertStringNotContainsString('dt-column-title">Paid since<', $html);
        self::assertStringContainsString('Print this list', $html);
        self::assertStringContainsString('class="payout-sheet"', $html);
        self::assertStringContainsString('data-dt-fit="1"', $html);
        self::assertStringContainsString('/assets/js/pay-vendors-print.js', $html);
        self::assertStringContainsString('/assets/js/print-trigger.js', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('Print checks', $html);
        self::assertStringNotContainsString('Close October', $html);
        self::assertStringContainsString('1 to pay', $html);
        self::assertStringContainsString('0 owe the store', $html);
        self::assertStringContainsString('A-1', $html);
        self::assertStringContainsString('—', $html);
        self::assertStringNotContainsString('class="owe-amount"', $html);
        self::assertStringNotContainsString('text-danger', $html);
        self::assertStringNotContainsString('<p class="alert">', $html);
        self::assertStringNotContainsString('Left off this payout', $html);
        self::assertStringNotContainsString('was not recorded', $html);
        self::assertStringNotContainsString('Still unpaid', $html);
    }

    public function testAPaymentProblemIsShown(): void
    {
        $partial = $this->page('', '', '', '', 'Open the drawer first.');
        self::assertStringNotContainsString('<p class="alert">', $partial);

        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Shared/Web/layout', [
            'title' => 'Pay',
            'displayName' => 'Web Probe',
            'slug' => 'webprobe',
            'nav' => true,
            'csrf' => 'token',
            'error' => 'Open the drawer first.',
            'notice' => null,
            'warning' => null,
            'body' => 'Shared/Web/not-found',
            'path' => '/webprobe/payouts',
            'actorRole' => 'owner',
        ]);
        $html = ob_get_clean();

        self::assertIsString($html);
        self::assertStringContainsString('id="flash-dialog"', $html);
        self::assertStringContainsString('data-kind="danger"', $html);
        self::assertStringContainsString('<p class="error">Open the drawer first.</p>', $html);
    }

    public function testASettledMonthSaysThePaymentWasNotRecorded(): void
    {
        $html = $this->page('00000000-0000-7000-8000-000000000022', 'check', 'settled');

        self::assertStringContainsString('is saved, and the payment was not recorded.', $html);
        self::assertStringNotContainsString('Print checks', $html);
        self::assertStringNotContainsString('Pay these vendors', $html);
    }

    public function testAVendorLeftOffIsNamed(): void
    {
        $html = $this->page('', '', '', '', null, [
            ['code' => 'IRIS', 'name' => 'Iris', 'mode' => 'hold'],
        ]);

        self::assertStringContainsString('Left off this payout: Iris (hold)', $html);
    }

    public function testAnAmountOwedLinksToCollectRent(): void
    {
        $html = $this->page('', '', '', '', null, [], 'booth-9', 49666);

        self::assertStringContainsString('href="/webprobe/rent/collect?vendor=vendor-1"', $html);
        self::assertStringNotContainsString('rent/collect?vendor=vendor-1&amp;booth=', $html);
        self::assertStringContainsString('class="owe-amount"', $html);
        self::assertStringContainsString('>($496.66)<', $html);
    }

    public function testAShortfallNamesTheEarlierRent(): void
    {
        $html = $this->page('', '', '', 'Still unpaid from September: $96.66. Eleven days at C-1 was $146.66, and $50.00 was paid.');

        self::assertStringContainsString('Still unpaid from September: $96.66. Eleven days at C-1 was $146.66, and $50.00 was paid.', $html);
    }

    public function testAPaidCheckMonthReprintsTheSavedPaper(): void
    {
        $html = $this->page('00000000-0000-7000-8000-000000000020', 'check', 'paid');

        self::assertStringContainsString('October is paid by check. 1 check, $72.00.', $html);
        self::assertStringContainsString('dt-column-title">Paid them<', $html);
        self::assertStringContainsString('Paid $72.00', $html);
        self::assertStringContainsString('No payment', $html);
        self::assertStringNotContainsString('dt-column-title">Pay them<', $html);
        self::assertStringNotContainsString('payout-due', $html);
        self::assertStringContainsString('/payouts/report?run=00000000-0000-7000-8000-000000000020', $html);
        self::assertStringContainsString('class="btn btn-primary" href="/webprobe/payouts/checks?run=00000000-0000-7000-8000-000000000020"', $html);
        self::assertStringContainsString('class="text-link" href="/webprobe/payouts/report?run=00000000-0000-7000-8000-000000000020"', $html);
        self::assertStringContainsString('Print each vendor\'s sales', $html);
        self::assertStringContainsString('Print checks', $html);
        self::assertStringNotContainsString('Pay these vendors', $html);
        self::assertStringContainsString('1 paid', $html);
    }

    public function testAPaidCashMonthDoesNotOfferChecks(): void
    {
        $html = $this->page('00000000-0000-7000-8000-000000000021', 'safe_cash', 'paid');

        self::assertStringContainsString('October is paid from the safe. $72.00 went out.', $html);
        self::assertStringContainsString('Paid $72.00', $html);
        self::assertStringContainsString('class="text-link" href="/webprobe/payouts/report?run=00000000-0000-7000-8000-000000000021"', $html);
        self::assertStringContainsString('Print each vendor\'s sales', $html);
        self::assertStringNotContainsString('Print checks', $html);
        self::assertStringNotContainsString('Pay these vendors', $html);
        $drawer = $this->page('00000000-0000-7000-8000-000000000021', 'drawer_cash', 'paid');
        self::assertStringContainsString('October is paid from the drawer. $72.00 went out.', $drawer);
    }

    public function testAPaidCheckMonthWithNothingToPaySaysSo(): void
    {
        $html = $this->page('00000000-0000-7000-8000-000000000020', 'check', 'paid', '', null, [], '', 0, 0);

        self::assertStringContainsString('October is paid by check. Nobody is getting a check.', $html);
        $other = $this->page('00000000-0000-7000-8000-000000000020', 'store_credit', 'paid', '', null, [], '', 0, 0);
        self::assertStringContainsString('>October is paid.</h1>', $other);
        self::assertStringNotContainsString('from the safe', $other);
        self::assertStringContainsString('No payment', $html);
        self::assertStringContainsString('0 paid', $html);
        self::assertStringNotContainsString('1 check', $html);
        self::assertStringNotContainsString('payout-due', $html);
    }

    /**
     * @param list<array{code: string, name: string, mode: string}> $excluded
     */
    private function page(
        string $runId,
        string $tender,
        string $status,
        string $note = '',
        ?string $error = null,
        array $excluded = [],
        string $boothId = '',
        int $owes = 0,
        int $check = 7200,
    ): string {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Feature/Payout/pay-vendors', [
            'slug' => 'webprobe',
            'csrf' => 'token',
            'displayName' => 'Web Probe',
            'monthName' => 'October',
            'error' => $error,
            'excluded' => $excluded,
            'savedRun' => $runId,
            'savedTender' => $tender,
            'savedStatus' => $status,
            'pack' => [[
                'vendor_id' => 'vendor-1',
                'code' => 'ADA',
                'name' => 'Ada Lane',
                'booth' => 'A-1',
                'boothId' => $boothId,
                'prior' => 0,
                'charged' => 30000,
                'paid' => 0,
                'payable' => 10200,
                'rent' => 3000,
                'monthRent' => 30000,
                'check' => $check,
                'owes' => $owes,
                'note' => $note,
            ], [
                'vendor_id' => 'vendor-2',
                'code' => 'GIN',
                'name' => 'Gina Holt',
                'booth' => '',
                'boothId' => '',
                'prior' => 0,
                'charged' => 0,
                'paid' => 0,
                'payable' => 0,
                'rent' => 0,
                'monthRent' => 0,
                'check' => 0,
                'owes' => 0,
                'note' => '',
            ]],
        ]);
        $html = ob_get_clean();
        self::assertIsString($html);

        return $html;
    }
}
