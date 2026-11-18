<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Catalog;

use PerfectConsign\Feature\Catalog\NameStanding;
use PHPUnit\Framework\TestCase;

final class NameStandingTest extends TestCase
{
    public function testTheListOpensOnActiveNames(): void
    {
        $rows = [
            ['id' => 'a', 'name' => 'Red', 'active' => true],
            ['id' => 'b', 'name' => 'Blue', 'active' => false],
            ['id' => 'c', 'name' => 'Green', 'active' => 't'],
            ['id' => 'd', 'name' => 'Gold', 'active' => 'f'],
        ];

        self::assertSame('active', NameStanding::chosen(''));
        self::assertSame('active', NameStanding::chosen('nope'));
        self::assertSame('inactive', NameStanding::chosen('inactive'));

        $active = NameStanding::split($rows, 'active');
        self::assertSame(2, $active['active']);
        self::assertSame(2, $active['inactive']);
        self::assertSame(['Red', 'Green'], array_column($active['rows'], 'name'));

        $inactive = NameStanding::split($rows, 'inactive');
        self::assertSame(['Blue', 'Gold'], array_column($inactive['rows'], 'name'));
    }
}
