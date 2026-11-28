<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Report;

use PerfectConsign\Feature\Report\VendorMoney;
use PerfectConsign\Feature\Report\VendorMoneyController;
use PerfectConsign\Shared\Http\Request;
use PerfectConsign\Tests\Feature\Coverage\CapturesPage;
use PerfectConsign\Tests\Feature\Coverage\MallDoor;
use PHPUnit\Framework\TestCase;

/**
 * The vendor register and rent payments pages and downloads: what reaches
 * the screen or the file for good and bad filters, and who may open them.
 */
final class VendorMoneyControllerTest extends TestCase
{
    use CapturesPage;

    public function testTheDownloadsCarryTheirColumnsAndARefusalStaysOnThePage(): void
    {
        $register = $this->capture(function (): void {
            $this->controller('vendor-register.csv', ['vendor' => MallDoor::VENDOR, 'range' => 'this_month'])->registerCsv(MallDoor::SLUG);
        });
        self::assertStringStartsWith('Date,"What happened",Booth,Debit,Credit,Balance', $register);
        $payments = $this->capture(function (): void {
            $this->controller('rent-payments.csv', ['paid_by' => 'card', 'range' => 'last_month'])->paymentsCsv(MallDoor::SLUG);
        });
        self::assertStringStartsWith('Date,Receipt,Vendor,Booth,Months,"How paid",Register,Cashier,Amount', $payments);

        $badVendor = $this->capture(function (): void {
            $this->controller('vendor-register.csv', ['vendor' => 'nobody'])->registerCsv(MallDoor::SLUG);
        });
        self::assertStringContainsString('Choose a vendor.', $badVendor);
        self::assertStringNotContainsString('What happened",Booth', $badVendor);
        $badWay = $this->capture(function (): void {
            $this->controller('rent-payments.csv', ['paid_by' => 'gold'])->paymentsCsv(MallDoor::SLUG);
        });
        self::assertStringContainsString('Choose how it was paid.', $badWay);
        $mixed = $this->capture(function (): void {
            $this->controller('rent-payments', ['range' => 'this_month', 'from' => '2026-01-01'])->payments(MallDoor::SLUG);
        });
        self::assertStringContainsString('Choose a range or two dates.', $mixed);
        $empty = $this->capture(function (): void {
            $this->controller('vendor-register', [])->register(MallDoor::SLUG);
        });
        self::assertStringContainsString('Choose a vendor to see their register.', $empty);

        $badDebts = $this->capture(function (): void {
            $this->controller('bad-debts.csv', ['range' => 'this_month'])->badDebtsCsv(MallDoor::SLUG);
        });
        self::assertStringStartsWith('Date,Vendor,Booth,What,Amount,"Taken back",Reason,Who', $badDebts);
        $badDebtDates = $this->capture(function (): void {
            $this->controller('bad-debts.csv', ['range' => 'this_month', 'from' => '2026-01-01'])->badDebtsCsv(MallDoor::SLUG);
        });
        self::assertStringContainsString('Choose a range or two dates.', $badDebtDates);
        self::assertStringNotContainsString('"Taken back"', $badDebtDates);
        $badDebtPage = $this->capture(function (): void {
            $this->controller('bad-debts', ['range' => 'last_month'])->badDebts(MallDoor::SLUG);
        });
        self::assertStringContainsString('Nothing was written off in these dates.', $badDebtPage);
    }

    public function testACashierCannotOpenThem(): void
    {
        foreach (['register', 'registerCsv', 'payments', 'paymentsCsv', 'badDebts', 'badDebtsCsv'] as $page) {
            $door = MallDoor::open($this, new Request('GET', '/' . MallDoor::SLUG . '/reports/' . $page, [], []));
            $door->session->set('actor_role', 'cashier');
            $controller = new VendorMoneyController($door->app, $door->actor, $door->mall, new VendorMoney($door->statements));
            $html = $this->capture(static function () use ($controller, $page): void {
                match ($page) {
                    'register' => $controller->register(MallDoor::SLUG),
                    'registerCsv' => $controller->registerCsv(MallDoor::SLUG),
                    'payments' => $controller->payments(MallDoor::SLUG),
                    'badDebts' => $controller->badDebts(MallDoor::SLUG),
                    'badDebtsCsv' => $controller->badDebtsCsv(MallDoor::SLUG),
                    default => $controller->paymentsCsv(MallDoor::SLUG),
                };
            });
            self::assertStringNotContainsString('Opening balance', $html, $page);
            self::assertStringNotContainsString('Date,', $html, $page);
        }
    }

    /**
     * @param array<string, string> $query
     */
    private function controller(string $page, array $query): VendorMoneyController
    {
        $door = MallDoor::open($this, new Request('GET', '/' . MallDoor::SLUG . '/reports/' . $page, $query, []));

        return new VendorMoneyController($door->app, $door->actor, $door->mall, new VendorMoney($door->statements));
    }
}
