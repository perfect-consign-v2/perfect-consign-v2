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

    public function testAPostedTotalIsKept(): void
    {
        $statements = $this->createStub(Statements::class);
        $statements->method('bound')->willReturnCallback(static function (string $sql): array {
            if (str_contains($sql, 'vendor_payable')) {
                return [['total' => 125]];
            }
            if (str_contains($sql, 'booth_rent_receivable')) {
                return [['total' => 40]];
            }
            if (str_contains($sql, 'vendor_receivable')) {
                return [['total' => 15]];
            }

            return [];
        });

        self::assertSame(
            ['payable' => 125, 'rent' => 40, 'clawback' => 15],
            (new VendorFigures($statements))->numbers('vendor-1'),
        );
    }
}
