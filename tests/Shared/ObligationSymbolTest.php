<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/bin/prove-obligations.php';

final class ObligationSymbolTest extends TestCase
{
    public function testAShareAliasDoesNotCoverTheMethod(): void
    {
        self::assertFalse(\symbolIsCovered('share', "AS share\n"));
        self::assertFalse(\symbolIsCovered('share', "function share(\n"));
        self::assertTrue(\symbolIsCovered('share', "share(\n"));
        self::assertTrue(\symbolIsCovered('share', "\$this->share(\n"));
    }

    public function testAPrivateHelperIsCoveredOnlyThroughTheTestedCaller(): void
    {
        $source = <<<'PHP'
final class Example
{
    public function seal(string $plain): string
    {
        return self::key();
    }

    private static function key(): string
    {
        return 'x';
    }

    private function hidden(): void
    {
    }
}
PHP;

        self::assertTrue(\privateHelperIsCovered($source, 'key', "seal(\n"));
        self::assertFalse(\privateHelperIsCovered($source, 'hidden', "seal(\n"));
        self::assertFalse(\privateHelperIsCovered($source, 'seal', "key(\n"));
    }

    public function testTheObligationBaseIsNotHead(): void
    {
        $base = \obligationBase(dirname(__DIR__, 2));
        self::assertNotSame('HEAD', $base);
        self::assertNotSame('', $base);
    }
}
