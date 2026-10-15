<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Pos;

use PerfectConsign\Feature\Register\RegisterStore;
use PerfectConsign\Shared\Http\View;
use PHPUnit\Framework\TestCase;

final class PayWindowTest extends TestCase
{
    public function testFinishSalePostsAndCloseDoesNot(): void
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Feature/Pos/pay-modal', [
            'slug' => 'demo',
            'csrf' => 'token',
            'open' => true,
            'remaining' => 1000,
            'cashFill' => 1000,
            'roundStep' => 1,
            'salesAvailable' => 0,
            'cart' => ['lines' => [], 'buyer_id' => '', 'buyer_name' => ''],
            'openPayment' => true,
            'payError' => '',
            'payCash' => '',
            'payCard' => '',
            'payCheck' => '',
            'payCheckNumber' => '',
            'payVendor' => '',
            'payGift' => '',
            'payGiftNumber' => '',
            'payCredit' => '',
            'payCreditNumber' => '',
        ]);
        $html = ob_get_clean();

        self::assertIsString($html);
        self::assertMatchesRegularExpression(
            '/<button class="btn btn-primary" id="pay-finish" type="submit"[^>]*>Finish sale<\\/button>/',
            $html
        );
        self::assertStringContainsString('data-close-modal="enter-payment">Close</button>', $html);
        self::assertStringNotContainsString('>Done</button>', $html);
        self::assertStringNotContainsString('Exact change', $html);
        $cash = strpos($html, 'Full Amount Cash');
        $card = strpos($html, 'Full Amount Card');
        $check = strpos($html, 'Full Amount Check');
        self::assertIsInt($cash);
        self::assertIsInt($card);
        self::assertIsInt($check);
        self::assertLessThan($card, $cash);
        self::assertLessThan($check, $card);
        self::assertStringContainsString('name="tender_gift_card"', $html);
        self::assertStringContainsString('name="store_credit_number"', $html);
        self::assertStringContainsString('data-due="10.00"', $html);
        self::assertStringContainsString('Choose the vendor this sale pays.', $html);
        self::assertStringContainsString('id="pay-vendor" name="tender_vendor_draw" inputmode="decimal" placeholder="0.00" value="" disabled', $html);
        self::assertStringContainsString('id="pay-gift-error" hidden', $html);
        self::assertStringContainsString('id="pay-error" role="alert" hidden', $html);
        self::assertStringNotContainsString('aria-invalid="true"', $html);
    }

    public function testAVendorWithSalesCanDrawAndAnErrorStaysOnTheGiftNumber(): void
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Feature/Pos/pay-modal', [
            'slug' => 'demo',
            'csrf' => 'token',
            'open' => true,
            'remaining' => 0,
            'cashFill' => 0,
            'roundStep' => 1,
            'salesAvailable' => 250,
            'cart' => ['lines' => [], 'buyer_id' => 'vendor-1', 'buyer_name' => 'Ada'],
            'openPayment' => true,
            'payError' => RegisterStore::GIFT_CERTIFICATE_MISSING,
            'payCash' => '',
            'payCard' => '',
            'payCheck' => '',
            'payCheckNumber' => '',
            'payVendor' => '',
            'payGift' => '',
            'payGiftNumber' => '',
            'payCredit' => '',
            'payCreditNumber' => '',
        ]);
        $html = ob_get_clean();

        self::assertIsString($html);
        self::assertStringContainsString('Available from sales: $2.50', $html);
        self::assertStringContainsString('data-due="0.00"', $html);
        self::assertStringNotContainsString('id="pay-vendor" name="tender_vendor_draw" inputmode="decimal" placeholder="0.00" value="" disabled', $html);
        self::assertStringContainsString('aria-invalid="true"', $html);
        self::assertStringNotContainsString('id="pay-gift-error" hidden', $html);
        self::assertStringContainsString('id="pay-error" role="alert" hidden', $html);
        self::assertStringContainsString('id="pay-gift-error">' . RegisterStore::GIFT_CERTIFICATE_MISSING, $html);
    }

    public function testABadAmountStaysInThePaymentWindow(): void
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Feature/Pos/pay-modal', [
            'slug' => 'demo',
            'csrf' => 'token',
            'open' => true,
            'remaining' => 1002,
            'cashFill' => 1002,
            'roundStep' => 1,
            'salesAvailable' => 0,
            'cart' => ['lines' => [['line_no' => 1]], 'buyer_id' => '', 'buyer_name' => ''],
            'openPayment' => true,
            'payError' => 'enter an amount in dollars and cents',
            'payCash' => 'abc',
            'payCard' => '',
            'payCheck' => '',
            'payCheckNumber' => '',
            'payVendor' => '',
            'payGift' => '',
            'payGiftNumber' => '',
            'payCredit' => '',
            'payCreditNumber' => '',
        ]);
        $html = ob_get_clean();

        self::assertIsString($html);
        self::assertStringNotContainsString('id="enter-payment" class="modal" role="dialog" aria-modal="true" aria-labelledby="enter-payment-title" hidden', $html);
        self::assertStringContainsString('id="pay-error" role="alert">enter an amount in dollars and cents', $html);
        self::assertStringContainsString('id="pay-cash" name="tender_cash" inputmode="decimal" placeholder="0.00" value="abc" aria-invalid="true"', $html);
        self::assertStringContainsString('id="pay-gift-error" hidden', $html);
    }

    public function testAVendorWithNoSalesCannotDraw(): void
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Feature/Pos/pay-modal', [
            'slug' => 'demo',
            'csrf' => 'token',
            'open' => true,
            'remaining' => 100,
            'cashFill' => 100,
            'roundStep' => 1,
            'salesAvailable' => 0,
            'cart' => ['lines' => [], 'buyer_id' => 'vendor-1', 'buyer_name' => 'Ada'],
            'openPayment' => true,
            'payError' => '',
            'payCash' => '',
            'payCard' => '',
            'payCheck' => '',
            'payCheckNumber' => '',
            'payVendor' => '',
            'payGift' => '',
            'payGiftNumber' => '',
            'payCredit' => '',
            'payCreditNumber' => '',
        ]);
        $html = ob_get_clean();

        self::assertIsString($html);
        self::assertStringContainsString('No Pay from Sales balance is available', $html);
        self::assertStringContainsString('id="pay-vendor" name="tender_vendor_draw" inputmode="decimal" placeholder="0.00" value="" disabled', $html);
        self::assertStringContainsString('data-due="1.00"', $html);
    }

    public function testSalesWithoutAVendorStayDisabled(): void
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Feature/Pos/pay-modal', [
            'slug' => 'demo',
            'csrf' => 'token',
            'open' => true,
            'remaining' => 100,
            'cashFill' => 100,
            'roundStep' => 1,
            'salesAvailable' => 250,
            'cart' => ['lines' => [], 'buyer_id' => '', 'buyer_name' => ''],
            'openPayment' => true,
            'payError' => '',
            'payCash' => '',
            'payCard' => '',
            'payCheck' => '',
            'payCheckNumber' => '',
            'payVendor' => '',
            'payGift' => '',
            'payGiftNumber' => '',
            'payCredit' => '',
            'payCreditNumber' => '',
        ]);
        $html = ob_get_clean();

        self::assertIsString($html);
        self::assertStringContainsString('Choose the vendor this sale pays.', $html);
        self::assertStringContainsString('id="pay-vendor" name="tender_vendor_draw" inputmode="decimal" placeholder="0.00" value="" disabled', $html);
    }
}
