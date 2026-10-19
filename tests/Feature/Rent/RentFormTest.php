<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Rent;

use PerfectConsign\Feature\Rent\RentForm;
use PHPUnit\Framework\TestCase;

final class RentFormTest extends TestCase
{
    public function testAZeroAmountIsBlankAndACentIsFilled(): void
    {
        $form = new RentForm();

        self::assertFalse($form->filled(''));
        self::assertFalse($form->filled('0'));
        self::assertFalse($form->filled('0.0'));
        self::assertFalse($form->filled('0.00'));
        self::assertTrue($form->filled('0.01'));
    }

    public function testTheDrawerOpensForAnEmptyWaiverOrAnyTender(): void
    {
        $form = new RentForm();

        self::assertTrue($form->needsDrawer([], '', '', '', ''));
        self::assertTrue($form->needsDrawer(['booth-1'], '1.00', '', '', ''));
        self::assertTrue($form->needsDrawer(['booth-1'], '', '1.00', '', ''));
        self::assertTrue($form->needsDrawer(['booth-1'], '', '', '1.00', ''));
        self::assertTrue($form->needsDrawer(['booth-1'], '', '', '', '1.00'));
        self::assertFalse($form->needsDrawer(['booth-1'], '', '', '', ''));
    }
}
