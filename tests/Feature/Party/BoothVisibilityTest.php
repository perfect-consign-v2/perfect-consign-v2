<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Party;

use PerfectConsign\Feature\Party\BoothVisibility;
use PHPUnit\Framework\TestCase;

final class BoothVisibilityTest extends TestCase
{
    public function testInactiveBoothsLeaveTheDefaultList(): void
    {
        $rows = [
            ['id' => 'a', 'code' => 'A1', 'active' => true],
            ['id' => 'b', 'code' => 'B1', 'active' => false],
        ];

        self::assertSame('active', BoothVisibility::chosen(''));
        self::assertSame('inactive', BoothVisibility::chosen('inactive'));
        self::assertSame(['A1'], array_column(BoothVisibility::only($rows, 'active'), 'code'));
        self::assertSame(['B1'], array_column(BoothVisibility::only($rows, 'inactive'), 'code'));
        self::assertSame(['active' => 1, 'inactive' => 1], BoothVisibility::counts($rows));
    }
}
