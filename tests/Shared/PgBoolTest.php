<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared;

use PerfectConsign\Shared\Database\PgBool;
use PHPUnit\Framework\TestCase;

final class PgBoolTest extends TestCase
{
    public function testABooleanBecomesThePostgresLiteral(): void
    {
        self::assertSame('true', PgBool::literal(true));
        self::assertSame('false', PgBool::literal(false));
    }
}
