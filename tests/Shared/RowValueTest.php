<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared;

use InvalidArgumentException;
use PerfectConsign\Shared\Database\CatalogLinks;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;
use PHPUnit\Framework\TestCase;

final class RowValueTest extends TestCase
{
    public function testANonStringIsRefused(): void
    {
        try {
            RowValue::string(1);
            self::fail('an integer was accepted as a string');
        } catch (InvalidArgumentException $error) {
            self::assertSame('expected a string', $error->getMessage());
        }
    }

    public function testACatalogTableNameMustBePlain(): void
    {
        $links = new CatalogLinks($this->createStub(Statements::class));
        try {
            $links->referenced('colors;drop');
            self::fail('a crafted table name was accepted');
        } catch (InvalidArgumentException $error) {
            self::assertSame('that catalog table is not a name', $error->getMessage());
        }
    }
}
