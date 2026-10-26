<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Vendor;

use PerfectConsign\Feature\Vendor\VendorFigures;
use PerfectConsign\Shared\Database\Statements;
use PHPUnit\Framework\TestCase;

final class VendorFiguresTest extends TestCase
{
    public function testAMissingTotalIsZero(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturn([]);

        self::assertSame(['payable' => 0, 'rent' => 0, 'clawback' => 0], (new VendorFigures($statements))->numbers('vendor-1'));
    }
}
