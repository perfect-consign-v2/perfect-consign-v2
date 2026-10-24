<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Http;

use PerfectConsign\Shared\Http\Csv;
use PHPUnit\Framework\TestCase;

final class CsvTest extends TestCase
{
    public function testACommaStaysInItsCellAndAFormulaIsText(): void
    {
        $text = Csv::document(
            ['vendor', 'amount'],
            [
                ['vendor' => 'Ada, Lane', 'amount' => '-500'],
                ['vendor' => '=1+1', 'amount' => '+10'],
                ['vendor' => '@cmd', 'amount' => '-2+2'],
            ]
        );

        self::assertStringContainsString('"Ada, Lane"', $text);
        self::assertStringContainsString("'=1+1", $text);
        self::assertStringContainsString("'+10", $text);
        self::assertStringContainsString("'@cmd", $text);
        self::assertStringContainsString('-500', $text);
        self::assertStringNotContainsString("'-500", $text);
        self::assertStringContainsString("'-2+2", $text);
        self::assertSame('-500', Csv::protect('-500'));
        self::assertSame("'=1+1", Csv::protect('=1+1'));
    }
}
