<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared\Tenant;

use PerfectConsign\Shared\Tenant\TenantRecord;
use PHPUnit\Framework\TestCase;

final class TenantRecordTest extends TestCase
{
    public function testThePracticeMallKeepsThePosterRole(): void
    {
        self::assertSame('stall_poster', TenantRecord::roleFor('mall', 'tenant_00000000000000000000000000000000'));
        self::assertSame(
            'poster_00000000000000000000000000000000',
            TenantRecord::roleFor('demo', 'tenant_00000000000000000000000000000000')
        );
    }
}
