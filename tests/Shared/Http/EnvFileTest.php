<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Http;

use PerfectConsign\Shared\Http\EnvFile;
use PHPUnit\Framework\TestCase;

final class EnvFileTest extends TestCase
{
    public function testQuotedValuesKeepSpacesAndDropSurroundingQuotes(): void
    {
        $vars = EnvFile::parse("STALL_APP_PASSWORD=\"correct horse battery staple\"\n");
        self::assertSame('correct horse battery staple', $vars['STALL_APP_PASSWORD'] ?? null);
    }

    public function testInlineHashCommentIsStrippedFromUnquotedValues(): void
    {
        $vars = EnvFile::parse("STALL_APP_PASSWORD=p@ss # prod\n");
        self::assertSame('p@ss', $vars['STALL_APP_PASSWORD'] ?? null);
    }

    public function testExportPrefixIsAccepted(): void
    {
        $vars = EnvFile::parse("export STALL_APP_PASSWORD=secret\n");
        self::assertSame('secret', $vars['STALL_APP_PASSWORD'] ?? null);
        self::assertArrayNotHasKey('export STALL_APP_PASSWORD', $vars);
    }

    public function testLeadingWhitespaceInsideQuotesIsKept(): void
    {
        $vars = EnvFile::parse("STALL_APP_PASSWORD=\" abc\"\n");
        self::assertSame(' abc', $vars['STALL_APP_PASSWORD'] ?? null);
    }

    public function testUnquotedValueIsTrimmed(): void
    {
        $vars = EnvFile::parse("STALL_APP_PASSWORD= abc \n");
        self::assertSame('abc', $vars['STALL_APP_PASSWORD'] ?? null);
    }
}
