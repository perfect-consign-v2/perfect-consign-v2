<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Vendor;

use PerfectConsign\Feature\Vendor\VendorLetter;
use PHPUnit\Framework\TestCase;

final class VendorLetterTest extends TestCase
{
    public function testTheLetterMatchesTheCheckToTheCent(): void
    {
        self::assertSame('0.00', VendorLetter::money(0));
        self::assertSame('1.01', VendorLetter::money(101));
        self::assertSame('-0.02', VendorLetter::money(-2));
        self::assertSame("Ada\nPayable 10.00\nRent you owe 2.50\n", VendorLetter::body('Ada', 1000, 250));
    }
}
