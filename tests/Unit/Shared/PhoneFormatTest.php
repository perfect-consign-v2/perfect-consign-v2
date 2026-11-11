<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Unit\Shared;

use PerfectConsign\Shared\Scalar\PersonPlace;
use PerfectConsign\Shared\Scalar\PhoneFormat;
use PHPUnit\Framework\TestCase;

final class PhoneFormatTest extends TestCase
{
    public function testTenDigitsBecomeParentheses(): void
    {
        self::assertSame('(541) 555-0100', PhoneFormat::display('541-555-0100'));
        self::assertSame('(541) 555-0100', PhoneFormat::display('5415550100'));
        self::assertSame('(541) 555-0100', PhoneFormat::display('1 (541) 555-0100'));
        self::assertSame('(541) 555-0100', PhoneFormat::normalize('5415550100'));
        self::assertSame('tel:5415550100', PhoneFormat::telHref('541-555-0100'));
        self::assertSame('5415550100', PhoneFormat::nationalDigits('1-541-555-0100'));
    }

    public function testOddValuesStayAsEntered(): void
    {
        self::assertSame('555-0100', PhoneFormat::display('555-0100'));
        self::assertSame('', PhoneFormat::display(''));
        self::assertSame('tel:5550100', PhoneFormat::telHref('555-0100'));
    }

    public function testPlaceLinesJoinStreetAndCity(): void
    {
        self::assertSame("1 Main\nEugene, OR 97401", PersonPlace::lines('1 Main', 'Eugene', 'OR', '97401'));
        self::assertSame('Eugene, OR 97401', PersonPlace::cityLine('Eugene', 'OR', '97401'));
        self::assertSame('1 Main', PersonPlace::lines('1 Main', '', '', ''));
    }
}
