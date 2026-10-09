<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Pos;

use PerfectConsign\Feature\Pos\GiftIssue;
use PerfectConsign\Shared\Http\View;
use PHPUnit\Framework\TestCase;

final class GiftSellPageTest extends TestCase
{
    public function testAClosedCertificateFormAssignsTheNumber(): void
    {
        $html = $this->sell();

        self::assertStringContainsString('value="gift_certificate" selected', $html);
        self::assertStringNotContainsString('value="gift_card" selected', $html);
        self::assertStringNotContainsString('value="store_credit" selected', $html);
        self::assertStringContainsString('id="gift-sell-error" hidden', $html);
        self::assertStringContainsString('id="gift-number-field" hidden', $html);
        self::assertStringContainsString('id="gift-check-field" hidden', $html);
        self::assertStringContainsString('value="cash" checked', $html);
        self::assertStringNotContainsString('value="card" checked', $html);
        self::assertStringNotContainsString('aria-invalid="true"', $html);
        self::assertStringNotContainsString('autofocus', $html);
        self::assertStringNotContainsString('nope', $html);
    }

    public function testAnOpenSaleFocusesTheAmountUntilANumberIsTyped(): void
    {
        $open = $this->sell(['openGift' => true]);
        $numbered = $this->sell(['giftFormNumber' => 'ABC']);
        $custom = $this->sell(['openGift' => true, 'giftCustom' => true]);

        self::assertStringContainsString('placeholder="0.00" value="" autofocus', $open);
        self::assertStringContainsString('id="gift-number-field">', $numbered);
        self::assertStringNotContainsString('maxlength="40" autofocus', $open);
        self::assertStringContainsString('maxlength="40" autofocus', $custom);
    }

    public function testACheckAndANumberErrorStayOnTheirOwnFields(): void
    {
        $check = $this->sell(['giftError' => GiftIssue::CHECK_REQUIRED, 'giftFormTender' => 'checks']);
        $number = $this->sell(['giftError' => GiftIssue::NUMBER_REQUIRED, 'giftCustom' => true]);
        $other = $this->sell(['giftError' => 'nope']);

        self::assertStringContainsString('id="gift_check" name="check_number" value="" aria-invalid="true"', $check);
        self::assertStringContainsString('id="gift-check-field">', $check);
        self::assertStringContainsString('id="gift-sell-error" hidden', $check);
        self::assertStringContainsString('id="gift_number" name="gift_number" value="" maxlength="40" aria-invalid="true"', $number);
        self::assertStringContainsString('id="gift-sell-error" hidden', $number);
        self::assertStringContainsString('id="gift-sell-error">nope', $other);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function sell(array $overrides = []): string
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Feature/Pos/gift-sell', $overrides + [
            'slug' => 'demo',
            'csrf' => 'token',
            'openGift' => false,
            'giftError' => '',
            'giftFormNumber' => '',
            'giftFormAmount' => '',
            'giftFormTender' => 'cash',
            'giftFormCheck' => '',
            'giftCustom' => false,
            'giftFormKind' => 'gift_certificate',
        ]);
        $html = ob_get_clean();
        self::assertIsString($html);

        return $html;
    }
}
