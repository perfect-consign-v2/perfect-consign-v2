<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Payout;

use PerfectConsign\Feature\Payout\CheckWords;
use PHPUnit\Framework\TestCase;

final class CheckWordsTest extends TestCase
{
    public function testACheckAmountIsWrittenInWords(): void
    {
        $words = new CheckWords();

        self::assertSame('Zero and 00/100', $words->words(0));
        self::assertSame('Two Hundred Forty and 00/100', $words->words(24000));
        self::assertSame('Two Hundred Ninety-Six and 65/100', $words->words(29665));
        self::assertSame('One Thousand Fifty and 25/100', $words->words(105025));
        self::assertSame('Fifteen and 05/100', $words->words(1505));
        // speak( chunk( hundreds( belowHundred( tens(
    }
}
