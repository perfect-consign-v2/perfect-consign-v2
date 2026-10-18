<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Ledger;

use PerfectConsign\Ledger\SourceLabel;
use PHPUnit\Framework\TestCase;

final class SourceLabelTest extends TestCase
{
    public function testAnUnknownSourceBecomesWords(): void
    {
        self::assertSame('Entry', SourceLabel::text(''));
        self::assertSame('Mystery Source', SourceLabel::text('mystery_source'));
        self::assertSame('Sale', SourceLabel::text('sale'));
    }
}
