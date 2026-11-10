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

    public function testBlankLinesAndCommentsAreIgnored(): void
    {
        $vars = EnvFile::parse("# ignore\n\nSTALL_APP_PASSWORD=secret\n# tail\n");
        self::assertSame(['STALL_APP_PASSWORD' => 'secret'], $vars);
    }

    public function testInvalidKeysAreIgnored(): void
    {
        $vars = EnvFile::parse("9bad=secret\nSTALL_APP_PASSWORD=ok\n-no=pe\n");
        self::assertSame(['STALL_APP_PASSWORD' => 'ok'], $vars);
    }

    public function testEscapedQuotesInsideQuotedValues(): void
    {
        $vars = EnvFile::parse('STALL_APP_PASSWORD="say \"hi\""' . "\n");
        self::assertSame('say "hi"', $vars['STALL_APP_PASSWORD'] ?? null);

        $vars = EnvFile::parse("STALL_APP_PASSWORD='it\\'s fine'\n");
        self::assertSame("it's fine", $vars['STALL_APP_PASSWORD'] ?? null);
    }

    public function testTrailingBackslashWithoutEscapeTargetIsLiteral(): void
    {
        $vars = EnvFile::parse("STALL_APP_PASSWORD=\"end\\\\\"\n");
        self::assertSame('end\\', $vars['STALL_APP_PASSWORD'] ?? null);
    }

    public function testAnUnreadableEnvFileFailsLoud(): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stall-env-' . bin2hex(random_bytes(4));
        self::assertNotFalse(file_put_contents($path, "STALL_APP_PASSWORD=secret\n"));
        try {
            chmod($path, 0000);
            if (is_readable($path)) {
                self::markTestSkipped('this filesystem cannot deny read for the env fail-loud path');
            }
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('the env file could not be read');
            EnvFile::apply($path);
        } finally {
            chmod($path, 0600);
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
