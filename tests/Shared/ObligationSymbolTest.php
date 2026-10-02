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

    public function testTheObligationBaseIsNotHead(): void
    {
        $base = \obligationBase(dirname(__DIR__, 2));
        self::assertNotSame('HEAD', $base);
        self::assertNotSame('', $base);
    }
}
